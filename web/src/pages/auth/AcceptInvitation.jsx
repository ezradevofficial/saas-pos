import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button, TextField } from '@/components/ds'
import { AuthForm, AuthPage, TextLink } from './AuthPage'

/** AUTH-05: the invitee sees who invited them, chooses a password and is signed in. */
export default function AcceptInvitation() {
  const { t } = useTranslation()
  const { token } = useParams()
  const { signIn } = useAuth()
  const invitation = useQuery({ queryKey: ['invitation', token], queryFn: () => api.get(`auth/invitations/${token}`) })
  const [values, setValues] = useState({ name: null, password: '' })

  const mutation = useMutation({
    mutationFn: () =>
      api.post(`auth/invitations/${token}/accept`, {
        name: values.name ?? invitation.data?.name ?? '',
        password: values.password,
      }),
    onSuccess: (data) => signIn(data),
  })
  const errors = formErrors(mutation.error, ['name', 'password'])
  const footer = (
    <p>
      <TextLink to="/sign-in">{t('auth.backToSignIn')}</TextLink>
    </p>
  )

  if (invitation.isPending) {
    return (
      <AuthPage title={t('auth.invitation.title')} footer={footer}>
        <p role="status" className="text-ink-muted">
          {t('common.loading')}
        </p>
      </AuthPage>
    )
  }

  if (invitation.isError) {
    return (
      <AuthPage title={t('auth.invitation.title')} footer={footer}>
        <Alert tone="warning" title={t('auth.invitation.unavailableTitle')}>
          {t('auth.invitation.unavailableText')}
        </Alert>
      </AuthPage>
    )
  }

  const { tenant_name: tenantName, email, phone } = invitation.data

  return (
    <AuthPage title={t('auth.invitation.title')} intro={t('auth.invitation.intro', { business: tenantName })} footer={footer}>
      <AuthForm onSubmit={() => mutation.mutate()} error={errors.form}>
        <TextField label={t('auth.fields.login')} value={email ?? phone ?? ''} readOnly disabled />
        <TextField
          label={t('auth.fields.name')}
          autoComplete="name"
          value={values.name ?? invitation.data.name ?? ''}
          onChange={(event) => setValues((current) => ({ ...current, name: event.target.value }))}
          error={errors.fields.name}
          required
        />
        <TextField
          label={t('auth.fields.newPassword')}
          help={t('auth.passwordHelp')}
          type="password"
          autoComplete="new-password"
          value={values.password}
          onChange={(event) => setValues((current) => ({ ...current, password: event.target.value }))}
          error={errors.fields.password}
          required
        />
        <Button type="submit" variant="primary" block loading={mutation.isPending}>
          {t('auth.invitation.submit')}
        </Button>
      </AuthForm>
    </AuthPage>
  )
}
