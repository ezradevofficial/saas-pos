import { useTranslation } from 'react-i18next'
import { Navigate, useLocation, useSearchParams } from 'react-router'
import { Alert, Button } from '@/components/ds'
import { useAuth } from './AuthProvider'
import { ENROL_PATH, safeNext } from './paths'


function Loading() {
  const { t } = useTranslation()
  return (
    <div role="status" className="flex min-h-screen items-center justify-center bg-surface-100 text-body text-ink-muted">
      {t('common.loading')}
    </div>
  )
}

/**
 * Pages for signed-in users. Without a token: the sign-in page, keeping
 * where the user was going in ?next=. A token that may only enrol a second
 * factor reaches only the enrolment page (AUTH-03).
 */
export function RequireAuth({ children, allowEnrolment = false }) {
  const { t } = useTranslation()
  const { token, status, enrolmentRequired, retry } = useAuth()
  const location = useLocation()

  if (!token) {
    const next = `${location.pathname}${location.search}`
    const query = next === '/' ? '' : `?next=${encodeURIComponent(next)}`
    return <Navigate to={`/sign-in${query}`} replace />
  }
  if (enrolmentRequired && !allowEnrolment) return <Navigate to={ENROL_PATH} replace />
  if (!enrolmentRequired && allowEnrolment && status === 'authenticated') return <Navigate to="/" replace />
  if (status === 'error') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-100 p-4">
        <Alert tone="danger" title={t('errors.profileTitle')} action={<Button onClick={retry}>{t('common.retry')}</Button>}>
          {t('errors.profileText')}
        </Alert>
      </div>
    )
  }
  if (status !== 'authenticated') return <Loading />
  return children
}

/** Sign-in, sign-up and the like: a signed-in user goes on to ?next= (or home). */
export function GuestOnly({ children }) {
  const { token, enrolmentRequired } = useAuth()
  const [params] = useSearchParams()
  if (token) return <Navigate to={enrolmentRequired ? ENROL_PATH : safeNext(params.get('next'))} replace />
  return children
}
