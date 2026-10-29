import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api, startSupportTab } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { useAuth } from '@/auth/useAuth'
import { Alert } from '@/components/ds'

/**
 * The one-time code from the URL fragment (never sent to a server). Reading
 * has no side effect: React may call a state initializer again for a render
 * it throws away, so the fragment is wiped in the effect, once committed.
 */
function readCode() {
  return new URLSearchParams(window.location.hash.slice(1)).get('code')
}

/**
 * RBAC-13c: the platform console opens a support session here with a
 * one-time code in the URL fragment. The code is exchanged once for the
 * session's token, the fragment is wiped, and the tenant back office opens
 * with the "Support session" bar.
 */
export default function SupportEnter() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { signIn } = useAuth()
  const [code] = useState(readCode)
  const [failure, setFailure] = useState(null)
  const started = useRef(false)

  useEffect(() => {
    if (window.location.hash) window.history.replaceState(null, '', window.location.pathname)
    if (started.current || !code) return
    started.current = true
    api
      .post('auth/support-session', { code })
      .then((response) => {
        // RBAC-13c: this tab only; the browser's own sign-in stays as it is.
        startSupportTab()
        signIn({ token: response.data.token, user: undefined })
        navigate('/', { replace: true })
      })
      .catch((error) => setFailure(errorMessage(error)))
  }, [code, navigate, signIn])

  const error = code ? failure : t('supportAccess.enter.missing')

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h1 text-ink">{t('supportAccess.enter.title')}</h1>
      {error ? <Alert tone="danger" title={error} /> : <p className="text-body text-ink-muted">{t('supportAccess.enter.opening')}</p>}
    </div>
  )
}
