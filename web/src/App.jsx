import { QueryClientProvider } from '@tanstack/react-query'
import { useState } from 'react'
import { createBrowserRouter, RouterProvider } from 'react-router'
import { createQueryClient } from './api/queryClient'
import { AuthProvider, useAuth } from './auth/AuthProvider'
import { routes } from './routes'
import { ThemeProvider } from './theme/ThemeProvider'

// The theme is remembered per user, so it sits inside the auth state.
function UserTheme({ children }) {
  const { user } = useAuth()
  return <ThemeProvider userId={user?.id}>{children}</ThemeProvider>
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
