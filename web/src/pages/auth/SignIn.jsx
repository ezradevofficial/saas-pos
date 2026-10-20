import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router'
import { api, deviceName } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { goToIdp, ssoReason, startSso } from '@/auth/sso'
import { Alert, Button, TextField } from '@/components/ds'
import { useTheme } from '@/theme/ThemeProvider'
import { AuthForm, AuthPage, TextLink } from './AuthPage'

/**
 * AUTH-11: "Continue with SSO". On a tenant's own host (BR-04) the host
 * names the organisation; on the shared page the work email does. Several
 * connections: the person chooses one. The API answers the identity
 * provider's URL and the browser leaves for it.
 */
function SsoPanel({ initialEmail, initialChoices, onBack }) {
  const { t } = useTranslation()
  const [email, setEmail] = useState(initialEmail)
  const [choices, setChoices] = useState(initialChoices)

  const mutation = useMutation({
    mutationFn: (body) => startSso(body),
    onSuccess: (data) => {
      if (data?.connections) setChoices(data.connections)
      else goToIdp(data)
    },
  })

  const errors = formErrors(mutation.error, ['email'])
  const leaving = mutation.isSuccess && !mutation.data?.connections

  return (
    <div className="flex flex-col gap-4">
      {leaving ? <Alert tone="info" title={t('auth.sso.redirecting')} /> : null}
      {choices ? (
        <div className="flex flex-col gap-3">
          <p className="text-label text-ink">{t('auth.sso.choose')}</p>
          {choices.map((choice) => (
            <Button
              key={choice.id}
              block
              loading={mutation.isPending && mutation.variables?.connectionId === choice.id}
              onClick={() => mutation.mutate({ email: email.trim() || undefined, host: window.location.host, connectionId: choice.id })}
            >
              {t('auth.sso.chooseFor', { name: choice.name })}
            </Button>
          ))}
        </div>
      ) : (
        <AuthForm onSubmit={() => mutation.mutate({ email: email.trim() })} error={errors.form} failure={mutation.error}>
          <TextField
            label={t('auth.sso.emailLabel')}
            help={t('auth.sso.emailHelp')}
            type="email"
            autoComplete="username"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            error={errors.fields.email}
            required
          />
          <Button type="submit" variant="primary" block loading={mutation.isPending}>
            {t('auth.sso.start')}
          </Button>
        </AuthForm>
      )}
      <div>
        <Button variant="ghost" onClick={onBack}>
          {t('auth.sso.back')}
        </Button>
      </div>
    </div>
  )
}

/** AUTH-01, AUTH-10: sign in with an email or phone number and a password, or with SSO (AUTH-11). */
export default function SignIn() {
  const { t } = useTranslation()
  const { signIn } = useAuth()
  const { brand } = useTheme()
  const navigate = useNavigate()
  const location = useLocation()
  const [values, setValues] = useState({ login: location.state?.login ?? '', password: '' })
  const [sso, setSso] = useState(false)
  const [hostChoices, setHostChoices] = useState(null)
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

  // On the tenant's host, the host is enough; a host without SSO falls back to the email.
  const hostStart = useMutation({
    mutationFn: () => startSso({ host: window.location.host }),
    onSuccess: (data) => {
      if (!data?.connections) return goToIdp(data)
      setHostChoices(data.connections)
      setSso(true)
    },
    onError: (error) => {
      if (error?.code === 'sso_not_found') setSso(true)
    },
  })

  const continueWithSso = () => {
    if (brand) hostStart.mutate()
    else setSso(true)
  }

  const ssoRequired = mutation.error?.code === 'sso_required'
  const errors = formErrors(mutation.error, ['login', 'password'])
  const notice = location.state?.notice
  const ssoError = location.state?.ssoError
  const initialEmail = values.login.includes('@') ? values.login : ''

  return (
    <AuthPage
      title={t('auth.signIn.title')}
      // BR-04: the tenant's own welcome text on its host (typed once, shown as entered).
      intro={brand?.welcome || t('auth.signIn.intro')}
      footer={
        <p>
          {t('auth.signIn.noAccount')} <TextLink to="/welcome">{t('auth.signIn.createAccount')}</TextLink>
        </p>
      }
    >
      {ssoError ? (
        <div className="mb-4">
          <Alert tone="danger" title={t('auth.sso.failedTitle')}>
            {ssoReason(t, ssoError)}
          </Alert>
        </div>
      ) : null}
      {sso ? (
        <SsoPanel
          initialEmail={initialEmail}
          initialChoices={hostChoices}
          onBack={() => {
            setSso(false)
            setHostChoices(null)
          }}
        />
      ) : (
        <div className="flex flex-col gap-4">
          <AuthForm onSubmit={() => mutation.mutate(values)} error={ssoRequired ? null : errors.form} failure={ssoRequired ? null : mutation.error}>
            {notice === 'passwordReset' ? <Alert tone="success" title={t('auth.reset.done')} /> : null}
            {ssoRequired ? (
              <Alert tone="info" title={t('auth.sso.requiredTitle')}>
                {t('auth.sso.requiredText')}
              </Alert>
            ) : null}
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
          <div className="flex items-center gap-3 text-caption text-ink-muted" aria-hidden="true">
            <span className="h-px flex-1 bg-border" />
            {t('auth.sso.or')}
            <span className="h-px flex-1 bg-border" />
          </div>
          {hostStart.isError && hostStart.error?.code !== 'sso_not_found' ? <Alert tone="danger" title={errorMessage(hostStart.error)} /> : null}
          <Button block icon="key" loading={hostStart.isPending} onClick={continueWithSso}>
            {t('auth.sso.continue')}
          </Button>
        </div>
      )}
    </AuthPage>
  )
}
