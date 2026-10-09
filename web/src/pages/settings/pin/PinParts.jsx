import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Dialog, StatusBadge, TextField } from '@/components/ds'
import { formatDateTime } from '@/lib/dates'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useLocale } from '@/lib/useLocale'

/** AUTH-06: a PIN is 4 to 6 digits (6 for people who approve overrides, AUTH-08); the API also refuses easy ones. */
function pinProblem(pin, sixDigits) {
  if (!/^\d{4,6}$/.test(pin)) return sixDigits ? 'posPin.errors.sixDigits' : 'posPin.errors.format'
  if (sixDigits && pin.length < 6) return 'posPin.errors.sixDigits'
  return null
}

/** Whether a PIN is set and must be changed at the till, and since when; never the PIN. */
export function PinStatus({ status }) {
  const { t } = useTranslation()
  const locale = useLocale()
  if (!status) return null
  if (!status.pin_set) return <StatusBadge tone="neutral">{t('posPin.status.notSet')}</StatusBadge>
  return (
    <span className="flex flex-col gap-1">
      {status.must_change ? <StatusBadge tone="warning">{t('posPin.status.mustChange')}</StatusBadge> : <StatusBadge tone="success">{t('posPin.status.set')}</StatusBadge>}
      {status.set_at ? <span className="text-caption text-ink-muted">{t('posPin.status.setAt', { date: formatDateTime(status.set_at, locale) })}</span> : null}
    </span>
  )
}

/**
 * Set a POS PIN (PUT me/pos-pin, or users/{id}/pos-pin for an
 * administrator). `withPassword`: the account password confirms it (one's
 * own PIN). The PIN is typed twice and never shown again.
 */
export function PinDialog({ title, endpoint, withPassword, sixDigits, intro, confirmLabel, onClose, onSaved }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [values, setValues] = useState({ password: '', pin: '', repeat: '' })
  const [checked, setChecked] = useState(false)
  const set = (name) => (event) => setValues((current) => ({ ...current, [name]: event.target.value }))

  const save = useMutation({
    mutationFn: () => api.put(endpoint, withPassword ? { password: values.password, pin: values.pin } : { pin: values.pin }),
    onSuccess: async (answer) => {
      await queryClient.invalidateQueries({ queryKey: ['pos-pin'] })
      onSaved?.(answer)
      onClose()
    },
  })
  const errors = formErrors(save.error, ['password', 'pin'])
  useErrorFocus(formRef, alertRef, save.error)

  const local = pinProblem(values.pin, sixDigits)
  const mismatch = values.repeat !== values.pin
  const submit = (event) => {
    event.preventDefault()
    setChecked(true)
    if (local || mismatch || (withPassword && !values.password)) return
    save.mutate()
  }

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
          <Button variant="primary" type="submit" form={formId} loading={save.isPending}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <form id={formId} ref={formRef} noValidate onSubmit={submit} className="flex flex-col gap-4 pt-1">
        {errors.form ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={errorMessage(save.error)} />
          </div>
        ) : null}
        {intro ? <p>{intro}</p> : null}
        {withPassword ? (
          <TextField
            type="password"
            label={t('posPin.fields.password')}
            help={t('posPin.fields.passwordHelp')}
            value={values.password}
            onChange={set('password')}
            error={errors.fields.password ?? (checked && !values.password ? t('posPin.errors.password') : undefined)}
            autoComplete="current-password"
            required
          />
        ) : null}
        <TextField
          type="password"
          inputMode="numeric"
          label={t('posPin.fields.pin')}
          help={sixDigits ? t('posPin.fields.pinHelpSix') : t('posPin.fields.pinHelp')}
          value={values.pin}
          onChange={set('pin')}
          error={errors.fields.pin ?? (checked && local ? t(local) : undefined)}
          maxLength={6}
          autoComplete="off"
          required
        />
        <TextField
          type="password"
          inputMode="numeric"
          label={t('posPin.fields.repeat')}
          value={values.repeat}
          onChange={set('repeat')}
          error={checked && !local && mismatch ? t('posPin.errors.mismatch') : undefined}
          maxLength={6}
          autoComplete="off"
          required
        />
      </form>
    </Dialog>
  )
}

/** Remove a POS PIN (DELETE), with the password when it is one's own. */
export function RemovePinDialog({ title, text, endpoint, withPassword, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [password, setPassword] = useState('')
  const remove = useMutation({
    mutationFn: () => (withPassword ? api.delete(endpoint, { password }) : api.delete(endpoint)),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['pos-pin'] })
      onClose()
    },
  })
  const errors = formErrors(remove.error, ['password'])
  return (
    <Dialog
      open
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('posPin.keep')}
          </Button>
          <Button variant="danger" loading={remove.isPending} onClick={() => remove.mutate()}>
            {t('posPin.remove')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errorMessage(remove.error)} /> : null}
        <p>{text}</p>
        {withPassword ? (
          <TextField
            type="password"
            label={t('posPin.fields.password')}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            error={errors.fields.password}
            autoComplete="current-password"
            required
          />
        ) : null}
      </div>
    </Dialog>
  )
}
