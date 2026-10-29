import { QueryClientProvider } from '@tanstack/react-query'
import { Suspense, useEffect, useState } from 'react'
import { createBrowserRouter, RouterProvider } from 'react-router'
import { RouteError } from './components/RouteError'
import { createQueryClient } from './api/queryClient'
import { AuthProvider } from './auth/AuthProvider'
import { useAuth } from './auth/useAuth'
import { setSentryTenant } from './lib/sentry'
import { routes } from './routes'
import { Toaster } from './components/ui/sonner'
import { ThemeProvider, useTheme } from './theme/ThemeProvider'
import { isDarkTheme } from './theme/themes'
import { useBrand } from './theme/useBrand'

// Toasts (exports and other background work) follow the app's theme, not the OS one.
function AppToaster() {
  const { theme } = useTheme()
  return <Toaster theme={isDarkTheme(theme) ? 'dark' : 'light'} position="bottom-right" />
}

// BR-02: the tenant's favicon replaces the platform's while its brand is shown.
function BrandFavicon() {
  const { brand } = useTheme()
  const href = brand?.assets?.favicon ?? null
  useEffect(() => {
    const link = document.querySelector('link[rel="icon"]')
    if (!link || !href) return undefined
    const before = link.getAttribute('href')
    link.setAttribute('href', href)
    return () => {
      if (before === null) link.removeAttribute('href')
      else link.setAttribute('href', before)
    }
  }, [href])
  return null
}

// The theme is remembered per user, so it sits inside the auth state. The
// tenant's brand (BR-02, BR-04, BR-08) is fetched at sign-in and when the
// company changes, or for the host before anyone signs in.
function UserTheme({ children }) {
  const { user } = useAuth()
  const brand = useBrand()
  // NFR-10: error reports carry the tenant id, never a name.
  useEffect(() => setSentryTenant(user?.tenant_id), [user?.tenant_id])
  return (
    <ThemeProvider userId={user?.id} brand={brand}>
      <BrandFavicon />
      {children}
      <AppToaster />
    </ThemeProvider>
  )
}

/** Data, auth and theme around the router (tests pass their own query client). */
export function AppProviders({ queryClient, children }) {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <UserTheme>{children}</UserTheme>
      </AuthProvider>
    </QueryClientProvider>
  )
}

export default function App() {
  const [queryClient] = useState(createQueryClient)
  // NFR-10: one error boundary above every route (RouteError), so page errors reach Sentry.
  const [router] = useState(() => createBrowserRouter([{ errorElement: <RouteError />, children: routes }]))
  return (
    <AppProviders queryClient={queryClient}>
      <Suspense fallback={null}>
        <RouterProvider router={router} />
      </Suspense>
    </AppProviders>
  )
}
