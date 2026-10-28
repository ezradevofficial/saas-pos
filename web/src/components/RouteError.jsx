import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useRouteError } from 'react-router'
import { Alert, Button } from '@/components/ds'
import { reportError } from '@/lib/sentry'
import { isStaleChunkError, reloadForNewBuild } from '@/lib/staleChunks'

/**
 * NFR-10: the router's error boundary for every page. A page that fails to
 * load because a deploy replaced its files reloads once; any other error is
 * sent to Sentry (the router catches render errors itself, so without this
 * they never reached it) and the user sees what happened and what to do.
 */
export function RouteError() {
  const error = useRouteError()
  const { t } = useTranslation()
  const stale = isStaleChunkError(error)
  // A stale page starts out "updating"; the effect reloads it, once, or reports the error.
  const [reloading, setReloading] = useState(stale)
  const handled = useRef(null)

  useEffect(() => {
    if (handled.current === error) return
    handled.current = error
    if (stale && reloadForNewBuild()) return
    setReloading(false)
    reportError(error, { boundary: 'route', stale_chunk: String(stale) })
  }, [error, stale])

  return (
    <main className="flex min-h-dvh items-center justify-center bg-surface-100 p-6">
      <div className="flex w-full max-w-md flex-col gap-4">
        {reloading ? (
          <p className="text-body text-ink-muted" aria-live="polite">{t('errors.route.updating')}</p>
        ) : (
          <>
            <Alert tone="danger" title={t(stale ? 'errors.route.staleTitle' : 'errors.route.title')}>
              {t(stale ? 'errors.route.staleBody' : 'errors.route.body')}
            </Alert>
            <div className="flex gap-2">
              <Button variant="primary" onClick={() => window.location.reload()}>{t('errors.route.reload')}</Button>
              <Button onClick={() => window.location.assign('/')}>{t('errors.route.home')}</Button>
            </div>
          </>
        )}
      </div>
    </main>
  )
}
