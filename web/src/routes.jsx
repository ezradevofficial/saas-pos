import { lazy, Suspense } from 'react'
import { GuestOnly, RequireAuth } from './auth/RequireAuth'
import { RequirePermission } from './auth/RequirePermission'
import { AppShell } from './layouts/AppShell'
import { AuthLayout } from './layouts/AuthLayout'
import {
  AUTOMATION_VIEW,
  CATEGORY_VIEW,
  CUSTOM_FIELD_VIEW,
  TEMPLATE_VIEW,
  DIMENSION_VIEW,
  EXCHANGE_RATE_VIEW,
  FISCAL_VIEW,
  ITEM_VIEW,
  NOTIFICATION_DELIVERY_VIEW,
  NOTIFICATION_TEMPLATE_VIEW,
  NUMBERING_VIEW,
  ORGANISATION_VIEW,
  PARTY_VIEW,
  PAYMENT_METHOD_VIEW,
  PAYMENT_VIEW,
  POS_HELD_VIEW,
  POS_SALE_VIEW,
  POS_SHIFT_VIEW,
  TAX_VIEW,
  UOM_VIEW,
  LAYOUT_VIEW,
  WORKFLOW_VIEW,
} from './layouts/navigation'
import AutomationRules from './pages/automation/AutomationRules'
import RuleEditor from './pages/automation/RuleEditor'
import AutomationRuns from './pages/automation/RunLog'
import Approvals from './pages/approvals/Approvals'
import EmailApproval from './pages/approvals/EmailApproval'
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
import Held from './pages/pos/Held'
import SaleDetail from './pages/pos/SaleDetail'
import Sales from './pages/pos/Sales'
import SalesDashboard from './pages/pos/SalesDashboard'
import ShiftDetail from './pages/pos/ShiftDetail'
import Shifts from './pages/pos/Shifts'
import Appearance from './pages/settings/Appearance'
import DashboardDesigner from './pages/settings/layouts/DashboardDesigner'
import NavigationEditor from './pages/settings/layouts/NavigationEditor'
import Currencies from './pages/settings/Currencies'
import CustomFields from './pages/settings/CustomFields'
import DocumentTemplates from './pages/settings/DocumentTemplates'
import TemplateDesigner from './pages/settings/templates/TemplateDesigner'
import Dimensions from './pages/settings/Dimensions'
import ExchangeRates from './pages/settings/ExchangeRates'
import Fiscal from './pages/settings/Fiscal'
import InviteUser from './pages/settings/InviteUser'
import MasterDataSharing from './pages/settings/MasterDataSharing'
import MyPosPin from './pages/settings/MyPosPin'
import NotificationDeliveries from './pages/settings/NotificationDeliveries'
import NotificationPreferences from './pages/settings/NotificationPreferences'
import NotificationTemplates from './pages/settings/NotificationTemplates'
import Numbering from './pages/settings/Numbering'
import Organisation from './pages/settings/Organisation'
import PaymentMethods from './pages/settings/PaymentMethods'
import Payments from './pages/settings/Payments'
import RoleDetail from './pages/settings/RoleDetail'
import Roles from './pages/settings/Roles'
import Security from './pages/settings/Security'
import Sessions from './pages/settings/Sessions'
import Taxes from './pages/settings/Taxes'
import PriceListDetail from './pages/settings/taxes/PriceListDetail'
import UserDetail from './pages/settings/UserDetail'
import Users from './pages/settings/Users'
import DocumentWorkflow from './pages/workflows/DocumentWorkflow'
import WorkflowBuilder from './pages/workflows/LazyWorkflowBuilder'
import WorkflowInsights from './pages/workflows/WorkflowInsights'
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
  // APR-08: an approval email's link works signed out or in; deciding needs the user to confirm.
  {
    element: <AuthLayout />,
    children: [
      { path: '/invitations/:token', element: <AcceptInvitation /> },
      { path: '/approvals/email/:token', element: <EmailApproval /> },
    ],
  },
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
      // APR-03, APR-04, APR-06: everyone's approvals inbox (the API decides what each user sees).
      { path: '/approvals/:approvalId?', element: <Approvals /> },
      // WF-10: any document's flow status (links from notifications and the run log; the API decides who sees it).
      { path: '/document-workflows/:documentType/:documentId', element: <DocumentWorkflow /> },
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
      // POS-12, TEN-07, H2: the POS back office (the API answers 403 module_inactive without the module).
      ...[
        ['/pos/dashboard', POS_SALE_VIEW, <SalesDashboard key="pos-dashboard" />],
        ['/pos/sales', POS_SALE_VIEW, <Sales key="pos-sales" />],
        ['/pos/sales/:saleId', POS_SALE_VIEW, <SaleDetail key="pos-sale" />],
        ['/pos/shifts', POS_SHIFT_VIEW, <Shifts key="pos-shifts" />],
        ['/pos/shifts/:shiftId', POS_SHIFT_VIEW, <ShiftDetail key="pos-shift" />],
        ['/pos/held', POS_HELD_VIEW, <Held key="pos-held" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
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
        // MD-03 follow-up: one price list's prices.
        ['/settings/taxes/price-lists/:priceListId', ['core.price_list.view', 'core.price_list.edit'], <PriceListDetail key="price-list" />],
        ['/settings/payment-methods', PAYMENT_METHOD_VIEW, <PaymentMethods key="payment-methods" />],
        // Concept note 7.1, 7.2: payment requests and receipts; the tax authority and its queue.
        ['/settings/payments', PAYMENT_VIEW, <Payments key="payments" />],
        ['/settings/fiscal', FISCAL_VIEW, <Fiscal key="fiscal" />],
        ['/settings/dimensions', DIMENSION_VIEW, <Dimensions key="dimensions" />],
        // CF-01, CF-03: custom fields of items and contacts.
        ['/settings/custom-fields', CUSTOM_FIELD_VIEW, <CustomFields key="custom-fields" />],
        // TPL-01..TPL-05: document templates and the designer.
        ['/settings/document-templates', TEMPLATE_VIEW, <DocumentTemplates key="document-templates" />],
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
      // WF-10: stage volumes and bottlenecks; the API decides who may see them (flow designers and document viewers).
      { path: '/settings/workflows/insights', element: <WorkflowInsights /> },
      {
        path: '/settings/workflows/:workflowId',
        handle: { wide: true },
        element: (
          <RequirePermission permission={WORKFLOW_VIEW}>
            <WorkflowBuilder />
          </RequirePermission>
        ),
      },
      // TPL-01: the template designer (full width for canvas, settings and preview).
      {
        path: '/settings/document-templates/:type',
        handle: { wide: true },
        element: (
          <RequirePermission permission={TEMPLATE_VIEW}>
            <TemplateDesigner />
          </RequirePermission>
        ),
      },
      // AUTO-01..AUTO-07: automation rules, their editor (and test mode) and the run log.
      ...[
        ['/settings/automation-rules', <AutomationRules key="automation-rules" />],
        ['/settings/automation-rules/:ruleId', <RuleEditor key="automation-rule" />],
        ['/settings/automation-runs', <AutomationRuns key="automation-runs" />],
      ].map(([path, page]) => ({ path, element: <RequirePermission permission={AUTOMATION_VIEW}>{page}</RequirePermission> })),
      // NUM-01: number formats per document type.
      { path: '/settings/numbering', element: <RequirePermission permission={NUMBERING_VIEW}><Numbering /></RequirePermission> },
      // AUTH-06: one's own POS PIN.
      { path: '/settings/pos-pin', element: <MyPosPin /> },
      { path: '/settings/appearance', element: <Appearance /> },
      // LAY-01, LAY-02: the organisation's and roles' dashboards and menus; anyone customises their own dashboard.
      { path: '/settings/layouts/dashboards', handle: { wide: true }, element: <RequirePermission permission={LAYOUT_VIEW} tenantWide><DashboardDesigner key="dashboards" /></RequirePermission> },
      { path: '/settings/layouts/navigation', handle: { wide: true }, element: <RequirePermission permission={LAYOUT_VIEW} tenantWide><NavigationEditor /></RequirePermission> },
      { path: '/dashboard/customise', handle: { wide: true }, element: <DashboardDesigner key="mine" personal /> },
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
