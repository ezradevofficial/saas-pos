import { QueryClientProvider } from '@tanstack/react-query'
import { useState } from 'react'
import { createBrowserRouter, RouterProvider } from 'react-router'
import { createQueryClient } from './api/queryClient'
import { AuthProvider, useAuth } from './auth/AuthProvider'
import { routes } from './routes'
import { Toaster } from './components/ui/sonner'
import { ThemeProvider, useTheme } from './theme/ThemeProvider'
import { isDarkTheme } from './theme/themes'

// Toasts (exports and other background work) follow the app's theme, not the OS one.
function AppToaster() {
  const { theme } = useTheme()
  return <Toaster theme={isDarkTheme(theme) ? 'dark' : 'light'} position="bottom-right" />
}

// The theme is remembered per user, so it sits inside the auth state.
function UserTheme({ children }) {
  const { user } = useAuth()
  return (
    <ThemeProvider userId={user?.id}>
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
  const [router] = useState(() => createBrowserRouter(routes))
  return (
    <AppProviders queryClient={queryClient}>
      <RouterProvider router={router} />
    </AppProviders>
  )
}
