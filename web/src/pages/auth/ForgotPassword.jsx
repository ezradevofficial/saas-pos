import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Button, TextField } from '@/components/ds'
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

  const mutation = useMutation({
    mutationFn: () => api.post('auth/password/forgot', { login: login.trim() }),
    onSuccess: () => navigate('/reset-password', { state: { login: login.trim(), sent: true } }),
  })
  const errors = formErrors(mutation.error, ['login'])

  return (
    <AuthPage
      title={t('auth.forgot.title')}
      intro={t('auth.forgot.intro')}
      footer={
        <p>
          <TextLink to="/sign-in">{t('auth.backToSignIn')}</TextLink>
        </p>
      }
    >
      <AuthForm onSubmit={() => mutation.mutate()} error={errors.form} failure={mutation.error}>
        <TextField
          label={t('auth.fields.login')}
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
