import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, TextField } from '@/components/ds'
import { AuthForm, AuthPage, TextLink } from './AuthPage'
import { CodeField } from './CodeField'

/** AUTH-04: the code from the email or SMS, and a new password. Every session ends. */
export default function ResetPassword() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const [values, setValues] = useState({ login: location.state?.login ?? '', code: '', password: '' })
  const set = (field) => (value) => setValues((current) => ({ ...current, [field]: value }))

  const mutation = useMutation({
    mutationFn: () => api.post('auth/password/reset', { ...values, login: values.login.trim() }),
    onSuccess: () => navigate('/sign-in', { state: { notice: 'passwordReset', login: values.login.trim() } }),
  })
  const errors = formErrors(mutation.error, ['login', 'code', 'password'])

  return (
    <AuthPage
      title={t('auth.reset.title')}
      intro={t('auth.reset.intro')}
      footer={
        <p>
          <TextLink to="/forgot-password" state={{ login: values.login }}>
            {t('auth.reset.newCode')}
          </TextLink>
        </p>
      }
    >
      <AuthForm onSubmit={() => mutation.mutate()} error={errors.form} failure={mutation.error}>
        {location.state?.sent ? <Alert tone="info" title={t('auth.reset.sentTitle')}>{t('auth.reset.sentText')}</Alert> : null}
        <TextField
          label={t('auth.fields.login')}
          autoComplete="username"
          value={values.login}
          onChange={(event) => set('login')(event.target.value)}
          error={errors.fields.login}
          required
        />
        <CodeField value={values.code} onChange={set('code')} error={errors.fields.code} />
        <TextField
          label={t('auth.fields.newPassword')}
          help={t('auth.passwordHelp')}
          type="password"
          autoComplete="new-password"
          value={values.password}
          onChange={(event) => set('password')(event.target.value)}
          error={errors.fields.password}
          required
        />
        <Button type="submit" variant="primary" block loading={mutation.isPending}>
          {t('auth.reset.submit')}
        </Button>
      </AuthForm>
    </AuthPage>
  )
}
