import { Suspense } from 'react'
import { useTranslation } from 'react-i18next'
import { Outlet } from 'react-router'

/** What a page shows while its chunk loads (NFR-02): quiet text, announced politely. */
export function PageLoading() {
  const { t } = useTranslation()
  return (
    <p role="status" aria-live="polite" className="text-sm text-ink-muted">
      {t('common.loading')}
    </p>
  )
}

/**
 * NFR-02: pages are lazy chunks (routes.jsx). Every layout renders its page
 * through this outlet, so the shell stays on screen while the page loads.
 */
export function PageOutlet({ children }) {
  return <Suspense fallback={<PageLoading />}>{children ?? <Outlet />}</Suspense>
}
