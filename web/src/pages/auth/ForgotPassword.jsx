import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Button, TextField } from '@/components/ds'
import { useSmsAvailable } from '@/lib/useSmsAvailable'
import { AuthForm, AuthPage, TextLink } from './AuthPage'

/**
 * AUTH-04: ask for a reset code. The answer is the same whether or not the
 * account exists, so the next page says "if an account exists".
 */
export default function ForgotPassword() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const [login, setLogin] = useState(location.state?.login ?? '')
  // AUTH-04: in email-only mode a code can only go to an email address.
  const sms = useSmsAvailable()

  const mutation = useMutation({
    mutationFn: () => api.post('auth/password/forgot', { login: login.trim() }),
    onSuccess: () => navigate('/reset-password', { state: { login: login.trim(), sent: true } }),
  })
  const errors = formErrors(mutation.error, ['login'])

  return (
    <AuthPage
      title={t('auth.forgot.title')}
      intro={sms ? t('auth.forgot.intro') : t('auth.forgot.introEmail')}
      footer={
        <p>
          <TextLink to="/sign-in">{t('auth.backToSignIn')}</TextLink>
        </p>
      }
    >
      <AuthForm onSubmit={() => mutation.mutate()} error={errors.form} failure={mutation.error}>
        <TextField
          label={sms ? t('auth.fields.login') : t('auth.fields.email')}
          help={sms ? undefined : t('auth.emailOnly')}
          type={sms ? 'text' : 'email'}
          autoComplete="username"
          value={login}
          onChange={(event) => setLogin(event.target.value)}
          error={errors.fields.login}
          required
        />
        <Button type="submit" variant="primary" block loading={mutation.isPending}>
          {t('auth.forgot.submit')}
        </Button>
      </AuthForm>
    </AuthPage>
  )
}
