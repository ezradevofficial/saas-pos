import { lazy } from 'react'
import { Navigate, Outlet } from 'react-router'
import { AuthLayout } from '@/layouts/AuthLayout'
import { PlatformAuthProvider, RequirePlatformAccess } from './PlatformAuth'
import { PlatformLayout } from './PlatformLayout'
import PlatformEnrol from './pages/PlatformEnrol'
import PlatformSetup from './pages/PlatformSetup'
import PlatformSignIn from './pages/PlatformSignIn'

// NFR-02: the console's pages are their own chunks; PlatformLayout renders
// them inside a Suspense boundary (PageOutlet).
const CatalogueConsole = lazy(() => import('./pages/CatalogueConsole'))
const PacksConsole = lazy(() => import('./pages/PacksConsole'))
const ReportsConsole = lazy(() => import('./pages/ReportsConsole'))
const StaffConsole = lazy(() => import('./pages/StaffConsole'))
const SupportConsole = lazy(() => import('./pages/SupportConsole'))
const NewTenant = lazy(() => import('./pages/NewTenant'))
const TenantDetail = lazy(() => import('./pages/TenantsConsole').then((m) => ({ default: m.TenantDetail })))
const TenantsList = lazy(() => import('./pages/TenantsConsole').then((m) => ({ default: m.TenantsList })))

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
        element: <AuthLayout unbranded />,
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
          // ADR 016: customer tenants, their subscriptions and invoices.
          { path: 'tenants', element: <TenantsList /> },
          { path: 'tenants/:tenantId', element: <TenantDetail /> },
          // RBAC-13: support requests and sessions; ADM-04: country packs; ADM-06: reports; ADM-07: staff.
          { path: 'support', element: <SupportConsole /> },
          { path: 'packs', element: <PacksConsole /> },
          { path: 'reports', element: <ReportsConsole /> },
          { path: 'staff', element: <StaffConsole /> },
        ],
      },
    ],
  },
]
