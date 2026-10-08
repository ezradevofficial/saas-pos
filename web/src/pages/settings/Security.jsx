import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useErrorFocus } from '@/lib/useErrorFocus'

const FIELDS = ['password_min_length', 'session_timeout_minutes', 'default_locale']

/** Whole numbers go as numbers; anything else as typed, so the API names the problem. */
const asNumber = (value) => (/^\d+$/.test(value.trim()) ? Number(value) : value)

function SettingsForm({ settings }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [values, setValues] = useState(() => ({
    password_min_length: String(settings.password_min_length),
    session_timeout_minutes: String(settings.session_timeout_minutes),
    default_locale: settings.default_locale,
  }))

  const mutation = useMutation({
    mutationFn: () =>
      api.patch('tenant/settings', {
        password_min_length: asNumber(values.password_min_length),
        session_timeout_minutes: asNumber(values.session_timeout_minutes),
        default_locale: values.default_locale,
      }),
    onSuccess: (response) => {
      queryClient.setQueryData(['tenant', 'settings'], response)
      queryClient.invalidateQueries({ queryKey: ['me'], exact: true })
    },
  })

  const errors = formErrors(mutation.error, FIELDS)
  const hasFieldErrors = Object.keys(mutation.error?.errors ?? {}).length > 0
  const formError = errors.form ? (hasFieldErrors ? errors.form : errorMessage(mutation.error)) : null
  useErrorFocus(formRef, alertRef, mutation.error)

  const set = (field) => (event) => {
    mutation.reset()
    setValues((current) => ({ ...current, [field]: event.target.value }))
  }

  return (
    <form
      ref={formRef}
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        mutation.mutate()
      }}
      className="flex flex-col gap-5"
    >
      {formError ? (
        <div ref={alertRef} tabIndex={-1} className="rounded-md">
          <Alert tone="danger" title={formError} />
        </div>
      ) : null}
      {mutation.isSuccess ? <Alert tone="success" title={t('security.saved')} /> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label={t('security.passwordMin')}
          help={t('security.passwordMinHelp')}
          type="number"
          inputMode="numeric"
          min={8}
          max={64}
          suffix={t('security.characters')}
          value={values.password_min_length}
          onChange={set('password_min_length')}
          error={errors.fields.password_min_length}
          required
        />
        <TextField
          label={t('security.sessionTimeout')}
          help={t('security.sessionTimeoutHelp')}
          type="number"
          inputMode="numeric"
          min={15}
          max={480}
          suffix={t('security.minutes')}
          value={values.session_timeout_minutes}
          onChange={set('session_timeout_minutes')}
          error={errors.fields.session_timeout_minutes}
          required
        />
        <Select
          label={t('security.defaultLocale')}
          help={t('security.defaultLocaleHelp')}
          value={values.default_locale}
          onChange={set('default_locale')}
          error={errors.fields.default_locale}
          options={[
            { value: 'en', label: t('shell.languages.en') },
            { value: 'fr', label: t('shell.languages.fr') },
          ]}
          required
        />
      </div>
      <div className="flex justify-end border-t border-border pt-4">
        <Button variant="primary" type="submit" loading={mutation.isPending}>
          {t('common.save')}
        </Button>
      </div>
    </form>
  )
}

/** AUTH-02, AUTH-09, L10N-01: the tenant's password minimum, session timeout and default language. */
export default function Security() {
  const { t } = useTranslation()
  const settings = useQuery({ queryKey: ['tenant', 'settings'], queryFn: () => api.get('tenant/settings') })

  return (
    <>
      <PageHeader title={t('security.title')} description={t('security.description')} />
      {settings.error ? (
        <Alert
          tone="danger"
          title={errorMessage(settings.error)}
          action={
            <Button variant="ghost" onClick={() => settings.refetch()}>
              {t('common.retry')}
            </Button>
          }
        />
      ) : null}
      {settings.data ? (
        <Card>
          <SettingsForm settings={settings.data.data} />
        </Card>
      ) : null}
    </>
  )
}
