import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api, deviceName } from '@/api/client'
import { useAuth } from '@/auth/useAuth'
import { readFragment, takeBinding } from '@/auth/sso'
import { Alert } from '@/components/ds'
import { AuthPage, TextLink } from './AuthPage'

/**
 * AUTH-11, ADR 023: where the identity provider's callback sends the
 * browser. The ticket (or an error code) is in the fragment, which never
 * reaches a server; it is read once and cleared from the address bar, then
 * handed back with this tab's binding secret. A second factor, when the IdP
 * asserted none, goes to the usual two-factor step.
 */
export default function SsoComplete() {
  const { t } = useTranslation()
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const [failure, setFailure] = useState(null)
  const started = useRef(false)

  useEffect(() => {
    if (started.current) return
    started.current = true
    const { ticket, error } = readFragment(window.location.hash)
    window.history.replaceState(window.history.state, '', window.location.pathname)
    const binding = takeBinding()

    if (error || !ticket || !binding) {
      navigate('/sign-in', { replace: true, state: { ssoError: error ?? 'ticket_invalid' } })
      return
    }

    api
      .post('auth/sso/complete', { ticket, binding, device_name: deviceName() })
      .then((data) => {
        if (data?.status === 'two_factor_required') navigate('/two-factor', { replace: true, state: { challengeId: data.challenge_id } })
        else signIn(data)
      })
      .catch((err) => setFailure(err))
  }, [navigate, signIn])

  return (
    <AuthPage
      title={t('auth.signIn.title')}
      footer={
        failure ? (
          <p>
            <TextLink to="/sign-in">{t('auth.sso.tryAgain')}</TextLink>
          </p>
        ) : null
      }
    >
      {failure ? (
        <Alert tone="danger" title={t('auth.sso.failedTitle')}>
          {failure.code === 'sso_ticket_invalid' ? t('auth.sso.ticketInvalid') : failure.message}
        </Alert>
      ) : (
        <p className="text-body text-ink-muted" role="status">
          {t('auth.sso.completing')}
        </p>
      )}
    </AuthPage>
  )
}
