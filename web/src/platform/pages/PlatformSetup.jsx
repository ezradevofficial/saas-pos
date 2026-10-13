import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, TextField } from '@/components/ds'
import { AuthForm, AuthPage } from '@/pages/auth/AuthPage'
import { usePlatformAuth } from '../usePlatformAuth'
import { platformApi } from '../platformClient'
import { TotpEnrolment } from './TotpEnrolment'

/**
 * ADR 013: the one-time setup link from `platform:user`. The staff member
 * sets a password, then sets up their authenticator app before anything else.
 */
export default function PlatformSetup() {
  const { t } = useTranslation()
  const { token } = useParams()
  const navigate = useNavigate()
  const { signIn, refresh } = usePlatformAuth()
  const [values, setValues] = useState({ password: '', password_confirmation: '' })
  const [twoFactor, setTwoFactor] = useState(null)

  const link = useQuery({ queryKey: ['platform', 'setup', token], queryFn: () => platformApi.get(`platform/auth/setup/${token}`), retry: false, enabled: !twoFactor })
  const save = useMutation({
    mutationFn: () => platformApi.post(`platform/auth/setup/${token}`, values),
    onSuccess: (data) => {
      signIn(data.token, data.user)
      setTwoFactor(data.two_factor)
    },
  })

  if (twoFactor) {
    return (
      <AuthPage title={t('platform.enrol.title')} intro={t('platform.enrol.intro')}>
        <TotpEnrolment
          setup={twoFactor}
          onDone={async () => {
            await refresh()
            navigate('/platform/catalogue', { replace: true })
          }}
        />
      </AuthPage>
    )
  }

  if (link.isError) {
    return (
      <AuthPage title={t('platform.setup.title')}>
        <Alert tone="danger" title={link.error.message} />
      </AuthPage>
    )
  }

  const errors = formErrors(save.error, ['password', 'password_confirmation'])
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))

  return (
    <AuthPage title={t('platform.setup.title')} intro={link.data ? t('platform.setup.intro', { name: link.data.data.name, email: link.data.data.email }) : null}>
      <AuthForm onSubmit={() => save.mutate()} error={errors.form} failure={save.error}>
        <TextField
          label={t('auth.fields.newPassword')}
          type="password"
          autoComplete="new-password"
          help={t('platform.setup.passwordHelp')}
          value={values.password}
          onChange={set('password')}
          error={errors.fields.password}
          required
        />
        <TextField
          label={t('platform.fields.confirmPassword')}
          type="password"
          autoComplete="new-password"
          value={values.password_confirmation}
          onChange={set('password_confirmation')}
          error={errors.fields.password_confirmation}
          required
        />
        <Button type="submit" variant="primary" block loading={save.isPending} disabled={!link.data}>
          {t('platform.setup.submit')}
        </Button>
      </AuthForm>
    </AuthPage>
  )
}
