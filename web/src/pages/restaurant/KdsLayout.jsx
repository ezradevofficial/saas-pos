import { Suspense } from 'react'
import { Outlet } from 'react-router'
import { PageLoading } from '@/layouts/PageOutlet'

/** REST-12: the kitchen display fills the screen: no sidebar, no banners. */
export function KdsLayout() {
  return (
    <div className="min-h-screen bg-surface-100 p-4 text-body text-ink">
      <main className="mx-auto flex max-w-none flex-col gap-4">
        <Suspense fallback={<PageLoading />}>
          <Outlet />
        </Suspense>
      </main>
    </div>
  )
}
