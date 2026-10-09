import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router'
import { api, deviceName } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button, TextField } from '@/components/ds'
import { useTheme } from '@/theme/ThemeProvider'
import { AuthForm, AuthPage, TextLink } from './AuthPage'

/** AUTH-01, AUTH-10: sign in with an email or phone number and a password. */
export default function SignIn() {
  const { t } = useTranslation()
  const { signIn } = useAuth()
  const { brand } = useTheme()
  const navigate = useNavigate()
  const location = useLocation()
  const [values, setValues] = useState({ login: location.state?.login ?? '', password: '' })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))

  const mutation = useMutation({
    mutationFn: (body) => api.post('auth/sign-in', { ...body, device_name: deviceName() }),
    onSuccess: (data) => {
      if (data?.status === 'two_factor_required') {
        navigate(`/two-factor${location.search}`, { state: { challengeId: data.challenge_id } })
      } else {
        signIn(data)
      }
    },
    onError: (error) => {
      if (error.code === 'unverified' && error.data?.challenge_id) {
        navigate(`/verify${location.search}`, { state: { challengeId: error.data.challenge_id } })
      }
    },
  })

  const errors = formErrors(mutation.error, ['login', 'password'])
  const notice = location.state?.notice

  return (
    <AuthPage
      title={t('auth.signIn.title')}
      // BR-04: the tenant's own welcome text on its host (typed once, shown as entered).
      intro={brand?.welcome || t('auth.signIn.intro')}
      footer={
        <p>
          {t('auth.signIn.noAccount')} <TextLink to="/sign-up">{t('auth.signIn.createAccount')}</TextLink>
        </p>
      }
    >
      <AuthForm onSubmit={() => mutation.mutate(values)} error={errors.form} failure={mutation.error}>
        {notice === 'passwordReset' ? <Alert tone="success" title={t('auth.reset.done')} /> : null}
        <TextField
          label={t('auth.fields.login')}
          autoComplete="username"
          value={values.login}
          onChange={set('login')}
          error={errors.fields.login}
          required
        />
        <TextField
          label={t('auth.fields.password')}
          type="password"
          autoComplete="current-password"
          value={values.password}
          onChange={set('password')}
          error={errors.fields.password}
          required
        />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <TextLink to="/forgot-password" state={{ login: values.login }}>
            {t('auth.signIn.forgot')}
          </TextLink>
          <Button type="submit" variant="primary" loading={mutation.isPending}>
            {t('auth.signIn.submit')}
          </Button>
        </div>
      </AuthForm>
    </AuthPage>
  )
}
