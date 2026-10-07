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

/** AUTH-03: the second step of a sign-in, with the authenticator app or SMS code. */
export default function TwoFactor() {
  const { t } = useTranslation()
  const { signIn } = useAuth()
  const location = useLocation()
  const challengeId = location.state?.challengeId ?? null
  const [code, setCode] = useState('')

  const mutation = useMutation({
    mutationFn: () => api.post('auth/two-factor/challenge', { challenge_id: challengeId, code, device_name: deviceName() }),
    onSuccess: (data) => signIn(data),
  })

  const footer = (
    <p>
      <TextLink to={`/sign-in${location.search}`}>{t('auth.backToSignIn')}</TextLink>
    </p>
  )

  if (!challengeId) {
    return (
      <AuthPage title={t('auth.twoFactor.title')} footer={footer}>
        <Alert tone="info" title={t('auth.twoFactor.missingTitle')}>
          {t('auth.twoFactor.missingText')}
        </Alert>
      </AuthPage>
    )
  }

  const errors = formErrors(mutation.error, ['code'])

  return (
    <AuthPage title={t('auth.twoFactor.title')} intro={t('auth.twoFactor.intro')} footer={footer}>
      <AuthForm onSubmit={() => mutation.mutate()} error={errors.form} failure={mutation.error}>
        <CodeField value={code} onChange={setCode} error={errors.fields.code} />
        <Button type="submit" variant="primary" block loading={mutation.isPending}>
          {t('auth.twoFactor.submit')}
        </Button>
      </AuthForm>
    </AuthPage>
  )
}
