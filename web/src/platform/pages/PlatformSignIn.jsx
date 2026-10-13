import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate } from 'react-router'
import { formErrors } from '@/api/formErrors'
import { Button, TextField } from '@/components/ds'
import { AuthForm, AuthPage } from '@/pages/auth/AuthPage'
import { CodeField } from '@/pages/auth/CodeField'
import { usePlatformAuth } from '../usePlatformAuth'
import { platformApi } from '../platformClient'

/**
 * ADR 013: platform staff sign in with email and password, then the code
 * from their authenticator app. Without two-factor yet, they go to enrolment.
 */
export default function PlatformSignIn() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { token, fullAccess, user, signIn } = usePlatformAuth()
  const [values, setValues] = useState({ email: '', password: '' })
  const [challenge, setChallenge] = useState(null)
  const [code, setCode] = useState('')

  const password = useMutation({
    mutationFn: () => platformApi.post('platform/auth/sign-in', values),
    onSuccess: (data) => {
      if (data.status === 'two_factor_required') {
        setChallenge(data.challenge)
        return
      }
      signIn(data.token, data.user)
      navigate('/platform/enrol', { replace: true })
    },
  })
  const verify = useMutation({
    mutationFn: () => platformApi.post('platform/auth/two-factor', { challenge, code }),
    onSuccess: (data) => {
      signIn(data.token, data.user)
      navigate('/platform/catalogue', { replace: true })
    },
    onError: (error) => {
      if (error.code === 'challenge_expired') setChallenge(null)
    },
  })

  if (token && user && fullAccess) return <Navigate to="/platform/catalogue" replace />

  if (challenge) {
    const errors = formErrors(verify.error, ['code'])
    return (
      <AuthPage title={t('platform.signIn.codeTitle')} intro={t('platform.signIn.codeIntro')}>
        <AuthForm onSubmit={() => verify.mutate()} error={errors.form} failure={verify.error}>
          <CodeField value={code} onChange={setCode} error={errors.fields.code} />
          <Button type="submit" variant="primary" block loading={verify.isPending}>
            {t('platform.signIn.verify')}
          </Button>
        </AuthForm>
      </AuthPage>
    )
  }

  const errors = formErrors(password.error ?? (verify.error?.code === 'challenge_expired' ? verify.error : null), ['email', 'password'])
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))

  return (
    <AuthPage title={t('platform.signIn.title')} intro={t('platform.signIn.intro')}>
      <AuthForm onSubmit={() => password.mutate()} error={errors.form} failure={password.error}>
        <TextField label={t('platform.fields.email')} type="email" autoComplete="username" value={values.email} onChange={set('email')} error={errors.fields.email} required />
        <TextField
          label={t('auth.fields.password')}
          type="password"
          autoComplete="current-password"
          value={values.password}
          onChange={set('password')}
          error={errors.fields.password}
          required
        />
        <Button type="submit" variant="primary" block loading={password.isPending}>
          {t('platform.signIn.submit')}
        </Button>
      </AuthForm>
    </AuthPage>
  )
}
