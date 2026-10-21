import { Navigate, Outlet } from 'react-router'
import { AuthLayout } from '@/layouts/AuthLayout'
import { PlatformAuthProvider, RequirePlatformAccess } from './PlatformAuth'
import { PlatformLayout } from './PlatformLayout'
import CatalogueConsole from './pages/CatalogueConsole'
import MarketplaceConsole, { PayoutsConsole } from './pages/MarketplaceConsole'
import PacksConsole from './pages/PacksConsole'
import ReportsConsole from './pages/ReportsConsole'
import StaffConsole from './pages/StaffConsole'
import SupportConsole from './pages/SupportConsole'
import NewTenant from './pages/NewTenant'
import PlatformEnrol from './pages/PlatformEnrol'
import PlatformSetup from './pages/PlatformSetup'
import PlatformSignIn from './pages/PlatformSignIn'
import { TenantDetail, TenantsList } from './pages/TenantsConsole'

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
          // ADR 016: customer tenants, their subscriptions and invoices.
          { path: 'tenants', element: <TenantsList /> },
          { path: 'tenants/:tenantId', element: <TenantDetail /> },
          // RBAC-13: support requests and sessions; ADM-04: country packs; ADM-06: reports; ADM-07: staff.
          { path: 'support', element: <SupportConsole /> },
          { path: 'packs', element: <PacksConsole /> },
          { path: 'reports', element: <ReportsConsole /> },
          { path: 'staff', element: <StaffConsole /> },
          // ADM-05, MKT-03: marketplace review and developers' payouts.
          { path: 'marketplace', element: <MarketplaceConsole /> },
          { path: 'payouts', element: <PayoutsConsole /> },
        ],
      },
    ],
  },
]
