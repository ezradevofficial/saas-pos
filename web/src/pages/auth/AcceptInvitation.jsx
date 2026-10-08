import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button, TextField } from '@/components/ds'
import { AuthForm, AuthPage, TextLink } from './AuthPage'

/** AUTH-05: the invitee sees who invited them, chooses a password and is signed in. */
export default function AcceptInvitation() {
  const { t } = useTranslation()
  const { token } = useParams()
  const { token: sessionToken, user, signIn, signOut } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  // Once accepted the invitation is used up (410): signing in clears the
  // cache, so the query is switched off first and never asked again.
  const [accepted, setAccepted] = useState(false)
  const invitation = useQuery({ queryKey: ['invitation', token], queryFn: () => api.get(`auth/invitations/${token}`), enabled: !accepted })
  const [values, setValues] = useState({ name: null, password: '' })

  const mutation = useMutation({
    mutationFn: () =>
      api.post(`auth/invitations/${token}/accept`, {
        name: values.name ?? invitation.data?.name ?? '',
        password: values.password,
      }),
    onSuccess: async (data) => {
      setAccepted(true)
      await queryClient.cancelQueries({ queryKey: ['invitation', token] })
      signIn(data)
      navigate('/', { replace: true })
    },
  })
  const errors = formErrors(mutation.error, ['name', 'password'])
  // Codes such as invitation_stale read as the web's own sentence (errorMessage).
  const hasFieldErrors = Object.keys(mutation.error?.errors ?? {}).length > 0
  const formError = errors.form ? (hasFieldErrors ? errors.form : errorMessage(mutation.error)) : null
  const footer = (
    <p>
      <TextLink to="/sign-in">{t('auth.backToSignIn')}</TextLink>
    </p>
  )

  // Accepting signs in as the new user, so whoever is signed in signs out first.
  if (sessionToken) {
    return (
      <AuthPage title={t('auth.invitation.title')}>
        <div className="flex flex-col gap-4">
          <Alert tone="info" title={t('auth.invitation.signedInTitle', { name: user?.name ?? user?.email ?? '' })}>
            {t('auth.invitation.signedInText')}
          </Alert>
          <div className="flex flex-wrap justify-end gap-2">
            <Button onClick={() => navigate('/')}>{t('auth.invitation.stay')}</Button>
            <Button variant="primary" onClick={() => signOut()}>
              {t('auth.invitation.signOutAndAccept')}
            </Button>
          </div>
        </div>
      </AuthPage>
    )
  }

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
      <AuthForm onSubmit={() => mutation.mutate()} error={formError} failure={mutation.error}>
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
