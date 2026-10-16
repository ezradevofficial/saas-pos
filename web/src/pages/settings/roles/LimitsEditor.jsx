import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, TextField } from '@/components/ds'

/** RBAC-06 limits the editor offers (owner decision A): percentages first, then base-currency amounts. */
const LIMITS = [
  { key: 'max_discount_percent', percent: true },
  { key: 'max_quote_discount_percent', percent: true },
  { key: 'max_refund_amount', percent: false },
  { key: 'max_approval_amount', percent: false },
]

const initial = (limits) => Object.fromEntries(LIMITS.map(({ key }) => [key, limits?.[key] ?? '']))

/**
 * RBAC-06, RBAC-12 (owner decision A, 2026-10-16): a role's numeric limits.
 * An empty field means no limit is set (the action then needs someone who
 * has one). System roles show theirs read-only; their copies are edited.
 */
export function LimitsEditor({ role, editable }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState(() => initial(role.limits))
  const [saved, setSaved] = useState(false)

  const mutation = useMutation({
    mutationFn: () => {
      const limits = Object.fromEntries(LIMITS.map(({ key }) => [key, values[key].trim() === '' ? null : values[key].trim()]))
      return api.put(`roles/${role.id}/limits`, { limits })
    },
    onSuccess: (response) => {
      queryClient.setQueryData(['roles', 'detail', role.id], response)
      setValues(initial(response.data.limits))
      setSaved(true)
    },
  })
  const fields = LIMITS.map(({ key }) => `limits.${key}`)
  const errors = formErrors(mutation.error, fields)
  const set = (key) => (event) => {
    setSaved(false)
    setValues((current) => ({ ...current, [key]: event.target.value }))
  }

  return (
    <Card title={t('roles.limits.title')} subtitle={t('roles.limits.subtitle')}>
      <form
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
        className="flex flex-col gap-4"
      >
        {errors.form ? <Alert tone="danger" title={errorMessage(mutation.error)} /> : null}
        {saved ? <Alert tone="success" title={t('roles.limits.saved')} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          {LIMITS.map(({ key, percent }) => (
            <TextField
              key={key}
              label={t(`roles.limits.fields.${key}`)}
              help={t(`roles.limits.help.${key}`)}
              value={values[key]}
              onChange={set(key)}
              placeholder={editable ? t('roles.limits.none') : undefined}
              inputMode="decimal"
              suffix={percent ? '%' : undefined}
              error={errors.fields[`limits.${key}`]}
              disabled={!editable}
              className="tabular-nums"
            />
          ))}
        </div>
        {!editable && LIMITS.every(({ key }) => values[key] === '') ? <p className="text-caption text-ink-muted">{t('roles.limits.noneSet')}</p> : null}
        {editable ? (
          <div className="flex justify-end">
            <Button variant="primary" type="submit" loading={mutation.isPending}>
              {t('roles.limits.save')}
            </Button>
          </div>
        ) : null}
      </form>
    </Card>
  )
}
