import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button } from '@/components/ds'
import { AuthForm, AuthPage } from './AuthPage'
import { CodeField } from './CodeField'

/**
 * AUTH-03: a role requires two-step sign-in and the user has not set it up.
 * Their token reaches only these routes until a code is confirmed.
 */
export default function TwoFactorEnrol() {
  const { t } = useTranslation()
  const { user, signOut, enrolmentDone } = useAuth()
  const [method, setMethod] = useState(null)
  const [setup, setSetup] = useState(null)
  const [code, setCode] = useState('')

  const start = useMutation({
    mutationFn: (chosen) => api.post(chosen === 'sms' ? 'me/two-factor/sms' : 'me/two-factor/totp'),
    onSuccess: (data, chosen) => {
      setMethod(chosen)
      setSetup(data)
      setCode('')
    },
  })
  const confirm = useMutation({
    mutationFn: () => api.post(method === 'sms' ? 'me/two-factor/sms/confirm' : 'me/two-factor/totp/confirm', { code }),
    onSuccess: () => enrolmentDone(),
  })

  const errors = formErrors(confirm.error ?? start.error, ['code'])
  const canUseSms = Boolean(user?.phone && user?.phone_verified_at)

  return (
    <AuthPage
      title={t('auth.enrol.title')}
      intro={t('auth.enrol.intro')}
      footer={
        <p>
          <Button variant="ghost" onClick={() => signOut()} className="px-0">
            {t('shell.signOut')}
          </Button>
        </p>
      }
    >
      {!setup ? (
        <div className="flex flex-col gap-4">
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <Button variant="primary" block loading={start.isPending && start.variables === 'totp'} onClick={() => start.mutate('totp')}>
            {t('auth.enrol.useApp')}
          </Button>
          {canUseSms ? (
            <Button block loading={start.isPending && start.variables === 'sms'} onClick={() => start.mutate('sms')}>
              {t('auth.enrol.useSms')}
            </Button>
          ) : null}
        </div>
      ) : (
        <AuthForm onSubmit={() => confirm.mutate()} error={errors.form}>
          {method === 'totp' ? (
            <div className="flex flex-col items-center gap-3">
              <p className="text-body text-ink-muted">{t('auth.enrol.scan')}</p>
              <img
                className="size-qr rounded-md border border-border"
                alt={t('auth.enrol.qrAlt')}
                src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.qr_svg)}`}
              />
              <p className="text-caption text-ink-muted">{t('auth.enrol.manual')}</p>
              <code className="font-mono text-caption break-all text-ink">{setup.secret}</code>
            </div>
          ) : (
            <p className="text-body text-ink-muted">{t('auth.enrol.smsSent', { destination: setup.destination_masked })}</p>
          )}
          <CodeField value={code} onChange={setCode} error={errors.fields.code} />
          <Button type="submit" variant="primary" block loading={confirm.isPending}>
            {t('auth.enrol.submit')}
          </Button>
        </AuthForm>
      )}
    </AuthPage>
  )
}
