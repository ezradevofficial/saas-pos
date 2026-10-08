import { lazy, Suspense } from 'react'
import { GuestOnly, RequireAuth } from './auth/RequireAuth'
import { RequirePermission } from './auth/RequirePermission'
import { AppShell } from './layouts/AppShell'
import { AuthLayout } from './layouts/AuthLayout'
import {
  CATEGORY_VIEW,
  DIMENSION_VIEW,
  EXCHANGE_RATE_VIEW,
  ITEM_VIEW,
  NOTIFICATION_DELIVERY_VIEW,
  NOTIFICATION_TEMPLATE_VIEW,
  ORGANISATION_VIEW,
  PARTY_VIEW,
  PAYMENT_METHOD_VIEW,
  TAX_VIEW,
  UOM_VIEW,
  WORKFLOW_VIEW,
} from './layouts/navigation'
import AcceptInvitation from './pages/auth/AcceptInvitation'
import ForgotPassword from './pages/auth/ForgotPassword'
import ResetPassword from './pages/auth/ResetPassword'
import SignIn from './pages/auth/SignIn'
import SignUp from './pages/auth/SignUp'
import TwoFactor from './pages/auth/TwoFactor'
import TwoFactorEnrol from './pages/auth/TwoFactorEnrol'
import Verify from './pages/auth/Verify'
import Categories from './pages/catalogue/Categories'
import ItemDetail from './pages/catalogue/ItemDetail'
import NewItem from './pages/catalogue/ItemForm'
import Items from './pages/catalogue/Items'
import Units from './pages/catalogue/Units'
import CreditLimitChanges from './pages/contacts/CreditLimitChanges'
import Parties from './pages/contacts/Parties'
import PartyDetail from './pages/contacts/PartyDetail'
import NewParty from './pages/contacts/PartyForm'
import Home from './pages/Home'
import Inbox from './pages/notifications/Inbox'
import NotFound from './pages/NotFound'
import Appearance from './pages/settings/Appearance'
import Currencies from './pages/settings/Currencies'
import Dimensions from './pages/settings/Dimensions'
import ExchangeRates from './pages/settings/ExchangeRates'
import InviteUser from './pages/settings/InviteUser'
import MasterDataSharing from './pages/settings/MasterDataSharing'
import NotificationDeliveries from './pages/settings/NotificationDeliveries'
import NotificationPreferences from './pages/settings/NotificationPreferences'
import NotificationTemplates from './pages/settings/NotificationTemplates'
import Organisation from './pages/settings/Organisation'
import PaymentMethods from './pages/settings/PaymentMethods'
import RoleDetail from './pages/settings/RoleDetail'
import Roles from './pages/settings/Roles'
import Security from './pages/settings/Security'
import Sessions from './pages/settings/Sessions'
import Taxes from './pages/settings/Taxes'
import UserDetail from './pages/settings/UserDetail'
import Users from './pages/settings/Users'
import WorkflowBuilder from './pages/workflows/LazyWorkflowBuilder'
import Workflows from './pages/workflows/Workflows'

// Development only: every design-system component in its states.
const ComponentGallery = import.meta.env.DEV ? lazy(() => import('./dev/ComponentGallery.jsx')) : null
const devRoutes = ComponentGallery
  ? [
      {
        path: '/dev/components',
        element: (
          <Suspense fallback={null}>
            <ComponentGallery />
          </Suspense>
        ),
      },
    ]
  : []

export const routes = [
  ...devRoutes,
  {
    element: (
      <GuestOnly>
        <AuthLayout />
      </GuestOnly>
    ),
    children: [
      { path: '/sign-in', element: <SignIn /> },
      { path: '/sign-up', element: <SignUp /> },
      { path: '/verify', element: <Verify /> },
      { path: '/two-factor', element: <TwoFactor /> },
      { path: '/forgot-password', element: <ForgotPassword /> },
      { path: '/reset-password', element: <ResetPassword /> },
    ],
  },
  // Open to everyone: a signed-in user is asked to sign out first.
  { element: <AuthLayout />, children: [{ path: '/invitations/:token', element: <AcceptInvitation /> }] },
  {
    element: (
      <RequireAuth allowEnrolment>
        <AuthLayout />
      </RequireAuth>
    ),
    children: [{ path: '/two-factor/enrol', element: <TwoFactorEnrol /> }],
  },
  {
    element: (
      <RequireAuth>
        <AppShell />
      </RequireAuth>
    ),
    children: [
      { path: '/', element: <Home /> },
      // MD-02: the catalogue.
      ...[
        ['/catalogue/items', ITEM_VIEW, <Items key="items" />],
        ['/catalogue/items/new', 'core.item.create', <NewItem key="new-item" />],
        ['/catalogue/items/:itemId', ITEM_VIEW, <ItemDetail key="item" />],
        ['/catalogue/categories', CATEGORY_VIEW, <Categories key="categories" />],
        ['/catalogue/units', UOM_VIEW, <Units key="units" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // MD-01: customers and suppliers.
      ...['customer', 'supplier'].flatMap((role) => {
        const base = `/contacts/${role}s`
        return [
          { path: base, element: <RequirePermission permission={PARTY_VIEW}><Parties key={role} role={role} /></RequirePermission> },
          { path: `${base}/new`, element: <RequirePermission permission="core.party.create"><NewParty key={role} role={role} /></RequirePermission> },
          { path: `${base}/:partyId`, element: <RequirePermission permission={PARTY_VIEW}><PartyDetail key={role} role={role} /></RequirePermission> },
        ]
      }),
      // WF-01, WF-10: credit limit change requests.
      { path: '/contacts/credit-limit-changes', element: <RequirePermission permission={PARTY_VIEW}><CreditLimitChanges /></RequirePermission> },
      {
        path: '/settings/organisation',
        element: (
          <RequirePermission permission={ORGANISATION_VIEW}>
            <Organisation />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/users',
        element: (
          <RequirePermission permission="core.user.view">
            <Users />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/users/invite',
        element: (
          <RequirePermission permission="core.user.invite">
            <InviteUser />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/users/:userId',
        element: (
          <RequirePermission permission="core.user.view">
            <UserDetail />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/roles',
        element: (
          <RequirePermission permission="core.role.view">
            <Roles />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/roles/new',
        element: (
          <RequirePermission permission="core.role.create" tenantWide>
            <RoleDetail />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/roles/:roleId',
        element: (
          <RequirePermission permission="core.role.view">
            <RoleDetail />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/security',
        element: (
          <RequirePermission permission="core.settings.edit" tenantWide>
            <Security />
          </RequirePermission>
        ),
      },
      ...[
        ['/settings/currencies', 'core.currency.view', <Currencies key="currencies" />],
        ['/settings/exchange-rates', EXCHANGE_RATE_VIEW, <ExchangeRates key="rates" />],
        ['/settings/taxes', TAX_VIEW, <Taxes key="taxes" />],
        ['/settings/payment-methods', PAYMENT_METHOD_VIEW, <PaymentMethods key="payment-methods" />],
        ['/settings/dimensions', DIMENSION_VIEW, <Dimensions key="dimensions" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      {
        path: '/settings/sharing',
        element: (
          <RequirePermission permission="core.master_data_settings.edit" tenantWide>
            <MasterDataSharing />
          </RequirePermission>
        ),
      },
      // WF-02, spec 6.4: workflows and the builder (full width for the canvas).
      { path: '/settings/workflows', element: <RequirePermission permission={WORKFLOW_VIEW}><Workflows /></RequirePermission> },
      {
        path: '/settings/workflows/:workflowId',
        handle: { wide: true },
        element: (
          <RequirePermission permission={WORKFLOW_VIEW}>
            <WorkflowBuilder />
          </RequirePermission>
        ),
      },
      { path: '/settings/appearance', element: <Appearance /> },
      { path: '/settings/sessions', element: <Sessions /> },
      // NOT-01, NOT-03, NOT-04, NOT-06: the inbox, one's own settings, and the admin's texts and log.
      { path: '/notifications', element: <Inbox /> },
      { path: '/settings/notifications', element: <NotificationPreferences /> },
      {
        path: '/settings/notification-templates',
        element: (
          <RequirePermission permission={NOTIFICATION_TEMPLATE_VIEW} tenantWide>
            <NotificationTemplates />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/notification-deliveries',
        element: (
          <RequirePermission permission={NOTIFICATION_DELIVERY_VIEW} tenantWide>
            <NotificationDeliveries />
          </RequirePermission>
        ),
      },
      { path: '*', element: <NotFound /> },
    ],
  },
]
