import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router'
import { api, deviceName } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button } from '@/components/ds'
import { AuthForm, AuthPage, TextLink } from './AuthPage'
import { CodeField } from './CodeField'

/** AUTH-01: the code sent to the new account's email or phone. */
export default function Verify() {
  const { t } = useTranslation()
  const { signIn } = useAuth()
  const location = useLocation()
  const [challenge, setChallenge] = useState({
    id: location.state?.challengeId ?? null,
    destination: location.state?.destination ?? null,
  })
  const [code, setCode] = useState('')
  const [resent, setResent] = useState(false)

  const verify = useMutation({
    mutationFn: () => api.post('auth/verify', { challenge_id: challenge.id, code, device_name: deviceName() }),
    onSuccess: (data) => signIn(data),
  })
  const resend = useMutation({
    mutationFn: () => api.post('auth/verify/resend', { challenge_id: challenge.id }),
    onSuccess: (data) => {
      setChallenge({ id: data.challenge_id, destination: data.destination_masked })
      setResent(true)
      verify.reset()
    },
  })

  const footer = (
    <p>
      <TextLink to="/sign-in">{t('auth.backToSignIn')}</TextLink>
    </p>
  )

  if (!challenge.id) {
    return (
      <AuthPage title={t('auth.verify.title')} footer={footer}>
        <Alert tone="info" title={t('auth.verify.missingTitle')}>
          {t('auth.verify.missingText')}
        </Alert>
      </AuthPage>
    )
  }

  const errors = formErrors(verify.error ?? resend.error, ['code'])

  return (
    <AuthPage
      title={t('auth.verify.title')}
      intro={challenge.destination ? t('auth.verify.introTo', { destination: challenge.destination }) : t('auth.verify.intro')}
      footer={footer}
    >
      <AuthForm onSubmit={() => verify.mutate()} error={errors.form} failure={verify.error ?? resend.error}>
        {resent && !resend.isPending ? <Alert tone="success" title={t('auth.verify.resent')} /> : null}
        <CodeField value={code} onChange={setCode} error={errors.fields.code} />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Button variant="ghost" onClick={() => resend.mutate()} loading={resend.isPending}>
            {t('auth.verify.resend')}
          </Button>
          <Button type="submit" variant="primary" loading={verify.isPending}>
            {t('auth.verify.submit')}
          </Button>
        </div>
      </AuthForm>
    </AuthPage>
  )
}
