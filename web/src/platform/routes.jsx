import { Navigate, Outlet } from 'react-router'
import { AuthLayout } from '@/layouts/AuthLayout'
import { PlatformAuthProvider, RequirePlatformAccess } from './PlatformAuth'
import { PlatformLayout } from './PlatformLayout'
import CatalogueConsole from './pages/CatalogueConsole'
import NewTenant from './pages/NewTenant'
import PlatformEnrol from './pages/PlatformEnrol'
import PlatformSetup from './pages/PlatformSetup'
import PlatformSignIn from './pages/PlatformSignIn'

/**
 * ADR 013: the platform console at /platform, outside the tenant app shell,
 * with its own staff session (never the tenant one).
 */
export const platformRoutes = [
  {
    path: '/platform',
    element: (
      <PlatformAuthProvider>
        <Outlet />
      </PlatformAuthProvider>
    ),
    children: [
      {
        element: <AuthLayout />,
        children: [
          { path: 'sign-in', element: <PlatformSignIn /> },
          { path: 'setup/:token', element: <PlatformSetup /> },
          { path: 'enrol', element: <PlatformEnrol /> },
        ],
      },
      {
        element: (
          <RequirePlatformAccess>
            <PlatformLayout />
          </RequirePlatformAccess>
        ),
        children: [
          { index: true, element: <Navigate to="/platform/catalogue" replace /> },
          { path: 'catalogue', element: <CatalogueConsole /> },
          // ONB-08: a tenant created for a customer, handed over by invitation.
          { path: 'tenants/new', element: <NewTenant /> },
        ],
      },
    ],
  },
]
