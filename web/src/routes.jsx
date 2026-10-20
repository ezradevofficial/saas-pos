import { lazy, Suspense } from 'react'
import { Navigate } from 'react-router'
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
  INVENTORY_ADJUSTMENT_VIEW,
  INVENTORY_COUNT_VIEW,
  INVENTORY_LABEL_PRINT,
  INVENTORY_REORDER_VIEW,
  INVENTORY_SETTINGS_VIEW,
  INVENTORY_STOCK_VIEW,
  INVENTORY_TRANSFER_VIEW,
  INVENTORY_VALUATION_VIEW,
  MANUFACTURING_BOM_VIEW,
  MANUFACTURING_ORDER_VIEW,
  MANUFACTURING_REPORT_VIEW,
  MANUFACTURING_SETTINGS_VIEW,
  STORES_ISSUE_VIEW,
  STORES_LEDGER_VIEW,
  STORES_REPORT_VIEW,
  FA_ASSET_VIEW,
  FA_DEPRECIATION_VIEW,
  FA_LABEL_PRINT,
  FA_MAINTENANCE_VIEW,
  FA_REPORT_VIEW,
  FA_SETTINGS_VIEW,
  STORES_REQUISITION_VIEW,
  STORES_RETURN_VIEW,
  HR_APPRAISAL_VIEW,
  HR_CHECKLIST_VIEW,
  HR_DISCIPLINARY_VIEW,
  HR_EMPLOYEE_VIEW,
  HR_LEAVE_VIEW,
  HR_POSITION_VIEW,
  HR_ROSTER_VIEW,
  ITEM_VIEW,
  NOTIFICATION_DELIVERY_VIEW,
  NOTIFICATION_TEMPLATE_VIEW,
  NUMBERING_VIEW,
  THEME_VIEW,
  POS_LAYOUT_VIEW,
  ORGANISATION_VIEW,
  PARTY_VIEW,
  PAYMENT_METHOD_VIEW,
  PAYMENT_VIEW,
  POS_HELD_VIEW,
  PROCUREMENT_INVOICE_VIEW,
  PROCUREMENT_LANDED_COST_VIEW,
  PROCUREMENT_PERFORMANCE_VIEW,
  PROCUREMENT_PO_VIEW,
  PROCUREMENT_RECEIPT_VIEW,
  PROCUREMENT_REQUISITION_VIEW,
  PROCUREMENT_RFQ_VIEW,
  PROCUREMENT_SETTINGS_VIEW,
  PROCUREMENT_TENDER_VIEW,
  POS_SALE_VIEW,
  POS_SHIFT_VIEW,
  TAX_VIEW,
  UOM_VIEW,
  LAYOUT_VIEW,
  WORKFLOW_VIEW,
} from './layouts/navigation'
import { IMPORT_PERMISSION } from './pages/settings/imports/importData'
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
import FormRecordPage from './pages/forms/FormRecordPage'
import FormRecords from './pages/forms/FormRecords'
import FormTypes from './pages/forms/FormTypes'
import Home from './pages/Home'
import BusinessType from './pages/onboarding/BusinessType'
import ModulePicker from './pages/onboarding/ModulePicker'
import { OnboardingLayout } from './pages/onboarding/OnboardingLayout'
import { SetupOverview, SetupStep } from './pages/onboarding/Setup'
import Welcome from './pages/onboarding/Welcome'
import Inbox from './pages/notifications/Inbox'
import AdjustmentDetail from './pages/inventory/AdjustmentDetail'
import AdjustmentForm from './pages/inventory/AdjustmentForm'
import Adjustments from './pages/inventory/Adjustments'
import CountDetail from './pages/inventory/CountDetail'
import CountForm from './pages/inventory/CountForm'
import Counts from './pages/inventory/Counts'
import InventorySettings from './pages/inventory/InventorySettings'
import ItemStock from './pages/inventory/ItemStock'
import Labels from './pages/inventory/Labels'
import Movements from './pages/inventory/Movements'
import ReorderRules from './pages/inventory/ReorderRules'
import StockOnHand from './pages/inventory/StockOnHand'
import TransferDetail from './pages/inventory/TransferDetail'
import TransferForm from './pages/inventory/TransferForm'
import Transfers from './pages/inventory/Transfers'
import Valuation from './pages/inventory/Valuation'
import BomDetail from './pages/manufacturing/BomDetail'
import BomForm from './pages/manufacturing/BomForm'
import Boms from './pages/manufacturing/Boms'
import BomVersion from './pages/manufacturing/BomVersion'
import Backflushes from './pages/manufacturing/Backflushes'
import MfgConsumption from './pages/manufacturing/Consumption'
import ManufacturingSettings from './pages/manufacturing/ManufacturingSettings'
import MfgOrderDetail from './pages/manufacturing/OrderDetail'
import MfgOrderForm from './pages/manufacturing/OrderForm'
import MfgOrders from './pages/manufacturing/Orders'
import RecipeCosting from './pages/manufacturing/RecipeCosting'
import MfgYield from './pages/manufacturing/Yield'
import FaAssetDetail from './pages/fixedAssets/AssetDetail'
import FaAssetForm from './pages/fixedAssets/AssetForm'
import FaAssets from './pages/fixedAssets/Assets'
import FaCategories from './pages/fixedAssets/Categories'
import FaLabels from './pages/fixedAssets/Labels'
import FaMaintenance from './pages/fixedAssets/Maintenance'
import { DisposalDetail as FaDisposalDetail, Disposals as FaDisposals, Revaluations as FaRevaluations, TransferDetail as FaTransferDetail, Transfers as FaTransfers } from './pages/fixedAssets/Movements'
import FaReports from './pages/fixedAssets/Reports'
import FaRuns, { RunDetail as FaRunDetail } from './pages/fixedAssets/Runs'
import FaSettings from './pages/fixedAssets/Settings'
import Consumption from './pages/stores/Consumption'
import IssueDetail from './pages/stores/IssueDetail'
import Issues from './pages/stores/Issues'
import StoresRequisitionDetail from './pages/stores/RequisitionDetail'
import StoresRequisitionForm from './pages/stores/RequisitionForm'
import StoresRequisitions from './pages/stores/Requisitions'
import ReturnDetail from './pages/stores/ReturnDetail'
import Returns from './pages/stores/Returns'
import StoreLedger from './pages/stores/StoreLedger'
import StoreStock from './pages/stores/StoreStock'
import Timesheets, { Devices as HrDevices, Periods as HrPeriods, Punches as HrPunches, TimeRules as HrTimeRules } from './pages/hr/Attendance'
import EmployeeProfile from './pages/hr/EmployeeProfile'
import Employees, { EmployeeForm } from './pages/hr/Employees'
import { SectionIndex } from './pages/hr/HrParts'
import LeaveRequests, { LeaveBalances, LeaveCalendar, LeaveTypes } from './pages/hr/Leave'
import { MyAppraisals, MyAttendance, MyLeave, MyProfile, MyRoster, MySwaps } from './pages/hr/MyHr'
import Positions from './pages/hr/Positions'
import PayEmployees, { PayEmployeeDetail } from './pages/payroll/Employees'
import Loans, { LoanDetail } from './pages/payroll/Loans'
import { PAYROLL_EMPLOYEE_VIEW, PAYROLL_LOAN_VIEW, PAYROLL_PAYSLIP_VIEW, PAYROLL_RETURN_VIEW, PAYROLL_RUN_VIEW, PAYROLL_SETTINGS_VIEW } from './layouts/navigation'
import Payslips, { MyPayslipDetail, MyPayslips, PayslipDetail } from './pages/payroll/Payslips'
import PayrollReturns from './pages/payroll/Returns'
import PayrollRuns, { RunDetail as PayrollRunDetail } from './pages/payroll/Runs'
import PayrollSettings from './pages/payroll/Settings'
import { AppraisalReview, Appraisals as HrAppraisals, CaseDetail, DisciplinaryCases, Onboarding } from './pages/hr/Records'
import RosterWeek, { RosterDetail, RosterList, RosterSetup, Swaps as HrSwaps } from './pages/hr/Rosters'
import NotFound from './pages/NotFound'
import InvoiceDetail from './pages/procurement/InvoiceDetail'
import InvoiceForm from './pages/procurement/InvoiceForm'
import LandedCostDetail from './pages/procurement/LandedCostDetail'
import LandedCostForm from './pages/procurement/LandedCostForm'
import OrderDetail from './pages/procurement/OrderDetail'
import OrderForm from './pages/procurement/OrderForm'
import { Invoices as ProcurementInvoices, LandedCosts, Orders, Receipts, Requisitions, Rfqs, Tenders } from './pages/procurement/ProcurementLists'
import ProcurementSettings from './pages/procurement/ProcurementSettings'
import ReceiptDetail from './pages/procurement/ReceiptDetail'
import ReceiptForm from './pages/procurement/ReceiptForm'
import RequisitionDetail from './pages/procurement/RequisitionDetail'
import RequisitionForm from './pages/procurement/RequisitionForm'
import RfqDetail from './pages/procurement/RfqDetail'
import RfqForm from './pages/procurement/RfqForm'
import SupplierPerformance from './pages/procurement/SupplierPerformance'
import TenderDetail from './pages/procurement/TenderDetail'
import TenderForm from './pages/procurement/TenderForm'
import { PortalLayout } from './pages/procurement/portal/PortalLayout'
import { PortalNotice, PortalSignIn, PortalTender } from './pages/procurement/portal/PortalPages'
import Ageing from './pages/loyalty/Ageing'
import CreditAccountDetail from './pages/loyalty/CreditAccountDetail'
import CreditAccounts from './pages/loyalty/CreditAccounts'
import GiftCardDetail from './pages/loyalty/GiftCardDetail'
import GiftCards from './pages/loyalty/GiftCards'
import FlaggedSales from './pages/loyalty/FlaggedSales'
import { CREDIT_VIEW, GIFT_CARD_VIEW, PROGRAMME_VIEW, PROMOTION_VIEW, SALE_VIEW } from './pages/loyalty/loyaltyData'
import LoyaltyProgramme from './pages/loyalty/Programme'
import PromotionEditor from './pages/loyalty/PromotionEditor'
import Promotions from './pages/loyalty/Promotions'
import './pages/loyalty/register'
import CrmCalendar from './pages/crm/Calendar'
import CrmSettings from './pages/crm/CrmSettings'
import { ACTIVITY_VIEW as CRM_ACTIVITY_VIEW, LEAD_VIEW as CRM_LEAD_VIEW, OPPORTUNITY_VIEW as CRM_OPPORTUNITY_VIEW, QUOTE_VIEW as CRM_QUOTE_VIEW, SETTINGS_VIEW as CRM_SETTINGS_VIEW, TARGET_VIEW as CRM_TARGET_VIEW } from './pages/crm/crmData'
import Leads, { LeadDetail, LeadForm } from './pages/crm/Leads'
import Opportunities, { OpportunityDetail, OpportunityForm } from './pages/crm/Opportunities'
import Quotes, { QuoteDetail, QuoteEditor } from './pages/crm/Quotes'
import CrmTargets from './pages/crm/Targets'
import './pages/crm/register'
import { Cashups as CashupsPage, Deliveries as DeliveriesPage, Riders as RidersPage } from './pages/ecommerce/Delivery'
import { CASHUP_VIEW as ECOM_CASHUP_VIEW, DELIVERY_VIEW as ECOM_DELIVERY_VIEW, ORDER_VIEW as ECOM_ORDER_VIEW, REPORT_VIEW as ECOM_REPORT_VIEW, RIDERS_VIEW as ECOM_RIDERS_VIEW, SETTINGS_VIEW as ECOM_SETTINGS_VIEW, WHATSAPP_VIEW as ECOM_WHATSAPP_VIEW } from './pages/ecommerce/ecommerceData'
import EcomInbox from './pages/ecommerce/Inbox'
import EcomOrders, { OrderDetail as EcomOrderDetail } from './pages/ecommerce/Orders'
import EcomReports from './pages/ecommerce/Reports'
import EcomStoreSettings from './pages/ecommerce/StoreSettings'
import { ShopLayout } from './shop/ShopLayout'
import { ShopAccount, ShopCart, ShopCheckout, ShopHome, ShopOrder, ShopProduct } from './shop/ShopPages'
import Held from './pages/pos/Held'
import SaleDetail from './pages/pos/SaleDetail'
import Sales from './pages/pos/Sales'
import SalesDashboard from './pages/pos/SalesDashboard'
import ShiftDetail from './pages/pos/ShiftDetail'
import Shifts from './pages/pos/Shifts'
import { ACCOUNT_VIEW, BANK_VIEW, BUDGET_VIEW, INVOICE_VIEW, JOURNAL_VIEW, PAYMENT_VIEW as ACCOUNTING_PAYMENT_VIEW, PERIOD_VIEW, REPORT_VIEW } from './pages/accounting/accountingData'
import BankAccounts, { Reconcile } from './pages/accounting/Bank'
import Budgets, { BudgetDetail } from './pages/accounting/Budgets'
import ChartOfAccounts from './pages/accounting/ChartOfAccounts'
import DocumentDetail from './pages/accounting/DocumentDetail'
import DocumentForm from './pages/accounting/DocumentForm'
import Documents from './pages/accounting/Documents'
import JournalDetail from './pages/accounting/JournalDetail'
import JournalForm from './pages/accounting/JournalForm'
import Journals from './pages/accounting/Journals'
import AccountingPayments, { PaymentDetail } from './pages/accounting/Payments'
import Periods from './pages/accounting/Periods'
import Reports, { GeneralLedger } from './pages/accounting/Reports'
import Appearance from './pages/settings/Appearance'
import Brand from './pages/settings/Brand'
import PosLayout from './pages/settings/PosLayout'
import DashboardDesigner from './pages/settings/layouts/DashboardDesigner'
import FormLayoutDesigner from './pages/settings/layouts/FormLayoutDesigner'
import NavigationEditor from './pages/settings/layouts/NavigationEditor'
import Currencies from './pages/settings/Currencies'
import CustomFields from './pages/settings/CustomFields'
import DocumentTemplates from './pages/settings/DocumentTemplates'
import TemplateDesigner from './pages/settings/templates/TemplateDesigner'
import Dimensions from './pages/settings/Dimensions'
import DataExport from './pages/settings/DataExport'
import WebhookDetail from './pages/settings/webhooks/WebhookDetail'
import Webhooks from './pages/settings/webhooks/Webhooks'
import Subscription from './pages/settings/Subscription'
import Imports from './pages/settings/Imports'
import ImportWizard from './pages/settings/imports/ImportWizard'
import Domains from './pages/settings/Domains'
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
import Usage from './pages/settings/Usage'
import PriceListDetail from './pages/settings/taxes/PriceListDetail'
import UserDetail from './pages/settings/UserDetail'
import Users from './pages/settings/Users'
import ApiKeys from './pages/settings/ApiKeys'
import { ApiReference } from './pages/developers/ApiReference'
import { Changelog, DevelopersHome, Guide } from './pages/developers/DeveloperPages'
import { DevelopersLayout } from './pages/developers/DevelopersLayout'
import DocumentWorkflow from './pages/workflows/DocumentWorkflow'
import WorkflowBuilder from './pages/workflows/LazyWorkflowBuilder'
import WorkflowInsights from './pages/workflows/WorkflowInsights'
import Workflows from './pages/workflows/Workflows'
import { platformRoutes } from './platform/routes'

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
  // ADR 013: the platform staff console, outside the tenant shell.
  ...platformRoutes,
  {
    element: (
      <GuestOnly>
        <AuthLayout />
      </GuestOnly>
    ),
    children: [
      { path: '/sign-in', element: <SignIn /> },
      // ONB-01: the country and language come first, then the account.
      { path: '/welcome', element: <Welcome /> },
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
  // ECOM-05: the storefront, open to everyone on the tenant's host; it never uses a tenant user's sign-in.
  // API-04: the developer portal, public (signed in or not).
  {
    path: '/developers',
    element: <DevelopersLayout />,
    children: [
      { index: true, element: <DevelopersHome /> },
      { path: 'guides/:guide', element: <Guide /> },
      { path: 'reference', element: <ApiReference /> },
      { path: 'errors', element: <Guide id="errors" /> },
      { path: 'changelog', element: <Changelog /> },
    ],
  },
  {
    path: '/shop/:store',
    element: <ShopLayout />,
    children: [
      { index: true, element: <ShopHome /> },
      { path: 'products/:slug', element: <ShopProduct /> },
      { path: 'cart', element: <ShopCart /> },
      { path: 'checkout', element: <ShopCheckout /> },
      { path: 'orders/:number', element: <ShopOrder /> },
      { path: 'account', element: <ShopAccount /> },
    ],
  },
  // PRC-07, PRC-08: the supplier portal, open to everyone; it never uses a tenant user's sign-in.
  {
    element: <PortalLayout />,
    children: [
      { path: '/supplier-portal/tender', element: <PortalTender /> },
      { path: '/supplier-portal/notices/:notice', element: <PortalNotice /> },
      { path: '/supplier-portal/:token', element: <PortalSignIn /> },
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
  // ONB-02..ONB-05: onboarding screens, outside the app shell (OnboardBusiness, OnboardModules, OnboardSetup).
  {
    element: (
      <RequireAuth>
        <RequirePermission permission="core.onboarding.edit" tenantWide>
          <OnboardingLayout />
        </RequirePermission>
      </RequireAuth>
    ),
    children: [
      { path: '/onboarding', element: <Navigate to="/onboarding/business" replace /> },
      // ONB-02, ONB-03: the business type and modules are the Owner's (core.onboarding.modules); the wizard is Admin's too.
      { path: '/onboarding/business', handle: { stage: 'business' }, element: <RequirePermission permission="core.onboarding.modules" tenantWide><BusinessType /></RequirePermission> },
      { path: '/onboarding/modules', handle: { stage: 'modules' }, element: <RequirePermission permission="core.onboarding.modules" tenantWide><ModulePicker /></RequirePermission> },
      { path: '/onboarding/setup', handle: { stage: 'setup' }, element: <SetupOverview /> },
      { path: '/onboarding/setup/:step', handle: { stage: 'setup' }, element: <SetupStep /> },
    ],
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
      // Loyalty (docs/modules/loyalty.md): the API answers 403 module_inactive without the module.
      ...[
        ['/loyalty/programme', PROGRAMME_VIEW, <LoyaltyProgramme key="loyalty-programme" />],
        ['/loyalty/promotions', PROMOTION_VIEW, <Promotions key="loyalty-promotions" />],
        ['/loyalty/promotions/new', 'loyalty.promotion.create', <PromotionEditor key="loyalty-promotion-new" />],
        ['/loyalty/promotions/:promotionId', PROMOTION_VIEW, <PromotionEditor key="loyalty-promotion" />],
        ['/loyalty/credit', CREDIT_VIEW, <CreditAccounts key="loyalty-credit" />],
        ['/loyalty/credit/:accountId', CREDIT_VIEW, <CreditAccountDetail key="loyalty-account" />],
        ['/loyalty/ageing', CREDIT_VIEW, <Ageing key="loyalty-ageing" />],
        ['/loyalty/gift-cards', GIFT_CARD_VIEW, <GiftCards key="loyalty-gift-cards" />],
        ['/loyalty/gift-cards/:cardId', GIFT_CARD_VIEW, <GiftCardDetail key="loyalty-gift-card" />],
        ['/loyalty/review', SALE_VIEW, <FlaggedSales key="loyalty-review" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // CRM and sales (docs/modules/crm.md, Screens): the API answers 403 module_inactive without the module.
      ...[
        ['/crm/leads', CRM_LEAD_VIEW, <Leads key="crm-leads" />],
        ['/crm/leads/new', 'crm.lead.create', <LeadForm key="crm-lead-new" />],
        ['/crm/leads/:leadId', CRM_LEAD_VIEW, <LeadDetail key="crm-lead" />],
        ['/crm/leads/:leadId/edit', 'crm.lead.edit', <LeadForm key="crm-lead-edit" />],
        ['/crm/opportunities', CRM_OPPORTUNITY_VIEW, <Opportunities key="crm-opportunities" />],
        ['/crm/opportunities/new', 'crm.opportunity.create', <OpportunityForm key="crm-opportunity-new" />],
        ['/crm/opportunities/:opportunityId', CRM_OPPORTUNITY_VIEW, <OpportunityDetail key="crm-opportunity" />],
        ['/crm/opportunities/:opportunityId/edit', 'crm.opportunity.edit', <OpportunityForm key="crm-opportunity-edit" />],
        ['/crm/quotes', CRM_QUOTE_VIEW, <Quotes key="crm-quotes" />],
        ['/crm/quotes/new', 'crm.quote.create', <QuoteEditor key="crm-quote-new" />],
        ['/crm/quotes/:quoteId', CRM_QUOTE_VIEW, <QuoteDetail key="crm-quote" />],
        ['/crm/quotes/:quoteId/edit', 'crm.quote.create', <QuoteEditor key="crm-quote-edit" />],
        ['/crm/calendar', CRM_ACTIVITY_VIEW, <CrmCalendar key="crm-calendar" />],
        ['/crm/targets', CRM_TARGET_VIEW, <CrmTargets key="crm-targets" />],
        ['/crm/settings', CRM_SETTINGS_VIEW, <CrmSettings key="crm-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // E-commerce and delivery (docs/modules/ecommerce.md, Screens): the API answers 403 module_inactive without the module.
      ...[
        ['/ecommerce/orders', ECOM_ORDER_VIEW, <EcomOrders key="ecom-orders" />],
        ['/ecommerce/orders/:orderId', ECOM_ORDER_VIEW, <EcomOrderDetail key="ecom-order" />],
        ['/ecommerce/deliveries', ECOM_DELIVERY_VIEW, <DeliveriesPage key="ecom-deliveries" />],
        ['/ecommerce/inbox', ECOM_WHATSAPP_VIEW, <EcomInbox key="ecom-inbox" />],
        ['/ecommerce/cashups', ECOM_CASHUP_VIEW, <CashupsPage key="ecom-cashups" />],
        ['/ecommerce/riders', ECOM_RIDERS_VIEW, <RidersPage key="ecom-riders" />],
        ['/ecommerce/reports', ECOM_REPORT_VIEW, <EcomReports key="ecom-reports" />],
        ['/ecommerce/settings', ECOM_SETTINGS_VIEW, <EcomStoreSettings key="ecom-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // INV-19, docs/modules/inventory.md (Screens): the Inventory back office (the API answers 403 module_inactive without the module).
      ...[
        ['/inventory/stock', INVENTORY_STOCK_VIEW, <StockOnHand key="inventory-stock" />],
        ['/inventory/items/:itemId', INVENTORY_STOCK_VIEW, <ItemStock key="inventory-item" />],
        ['/inventory/movements', INVENTORY_STOCK_VIEW, <Movements key="inventory-movements" />],
        ['/inventory/transfers', INVENTORY_TRANSFER_VIEW, <Transfers key="inventory-transfers" />],
        ['/inventory/transfers/new', 'inventory.transfer.create', <TransferForm key="inventory-transfer-new" />],
        ['/inventory/transfers/:transferId', INVENTORY_TRANSFER_VIEW, <TransferDetail key="inventory-transfer" />],
        ['/inventory/adjustments', INVENTORY_ADJUSTMENT_VIEW, <Adjustments key="inventory-adjustments" />],
        ['/inventory/adjustments/new', 'inventory.adjustment.create', <AdjustmentForm key="inventory-adjustment-new" />],
        ['/inventory/adjustments/:adjustmentId', INVENTORY_ADJUSTMENT_VIEW, <AdjustmentDetail key="inventory-adjustment" />],
        ['/inventory/counts', INVENTORY_COUNT_VIEW, <Counts key="inventory-counts" />],
        ['/inventory/counts/new', 'inventory.count.create', <CountForm key="inventory-count-new" />],
        ['/inventory/counts/:countId', INVENTORY_COUNT_VIEW, <CountDetail key="inventory-count" />],
        ['/inventory/reorder', INVENTORY_REORDER_VIEW, <ReorderRules key="inventory-reorder" />],
        ['/inventory/valuation', INVENTORY_VALUATION_VIEW, <Valuation key="inventory-valuation" />],
        ['/inventory/labels', INVENTORY_LABEL_PRINT, <Labels key="inventory-labels" />],
        ['/inventory/settings', INVENTORY_SETTINGS_VIEW, <InventorySettings key="inventory-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // Procurement (docs/modules/procurement.md, Screens): the API answers 403 module_inactive without the module.
      ...[
        ['/procurement/requisitions', PROCUREMENT_REQUISITION_VIEW, <Requisitions key="prc-requisitions" />],
        ['/procurement/requisitions/new', 'procurement.requisition.create', <RequisitionForm key="prc-requisition-new" />],
        ['/procurement/requisitions/:requisitionId', PROCUREMENT_REQUISITION_VIEW, <RequisitionDetail key="prc-requisition" />],
        ['/procurement/requisitions/:requisitionId/edit', 'procurement.requisition.create', <RequisitionForm key="prc-requisition-edit" />],
        ['/procurement/rfqs', PROCUREMENT_RFQ_VIEW, <Rfqs key="prc-rfqs" />],
        ['/procurement/rfqs/new', 'procurement.rfq.create', <RfqForm key="prc-rfq-new" />],
        ['/procurement/rfqs/:rfqId', PROCUREMENT_RFQ_VIEW, <RfqDetail key="prc-rfq" />],
        ['/procurement/rfqs/:rfqId/edit', 'procurement.rfq.create', <RfqForm key="prc-rfq-edit" />],
        ['/procurement/tenders', PROCUREMENT_TENDER_VIEW, <Tenders key="prc-tenders" />],
        ['/procurement/tenders/new', 'procurement.tender.create', <TenderForm key="prc-tender-new" />],
        ['/procurement/tenders/:tenderId', PROCUREMENT_TENDER_VIEW, <TenderDetail key="prc-tender" />],
        ['/procurement/tenders/:tenderId/edit', 'procurement.tender.create', <TenderForm key="prc-tender-edit" />],
        ['/procurement/orders', PROCUREMENT_PO_VIEW, <Orders key="prc-orders" />],
        ['/procurement/orders/new', 'procurement.po.create', <OrderForm key="prc-order-new" />],
        ['/procurement/orders/:orderId', PROCUREMENT_PO_VIEW, <OrderDetail key="prc-order" />],
        ['/procurement/orders/:orderId/edit', 'procurement.po.create', <OrderForm key="prc-order-edit" />],
        ['/procurement/receipts', PROCUREMENT_RECEIPT_VIEW, <Receipts key="prc-receipts" />],
        ['/procurement/receipts/new', 'procurement.receipt.create', <ReceiptForm key="prc-receipt-new" />],
        ['/procurement/receipts/:receiptId', PROCUREMENT_RECEIPT_VIEW, <ReceiptDetail key="prc-receipt" />],
        ['/procurement/invoices', PROCUREMENT_INVOICE_VIEW, <ProcurementInvoices key="prc-invoices" />],
        ['/procurement/invoices/new', 'procurement.invoice.create', <InvoiceForm key="prc-invoice-new" />],
        ['/procurement/invoices/:invoiceId', PROCUREMENT_INVOICE_VIEW, <InvoiceDetail key="prc-invoice" />],
        ['/procurement/landed-costs', PROCUREMENT_LANDED_COST_VIEW, <LandedCosts key="prc-landed-costs" />],
        ['/procurement/landed-costs/new', 'procurement.landed_cost.create', <LandedCostForm key="prc-landed-cost-new" />],
        ['/procurement/landed-costs/:landedCostId', PROCUREMENT_LANDED_COST_VIEW, <LandedCostDetail key="prc-landed-cost" />],
        ['/procurement/landed-costs/:landedCostId/edit', 'procurement.landed_cost.create', <LandedCostForm key="prc-landed-cost-edit" />],
        ['/procurement/supplier-performance', PROCUREMENT_PERFORMANCE_VIEW, <SupplierPerformance key="prc-performance" />],
        ['/procurement/settings', PROCUREMENT_SETTINGS_VIEW, <ProcurementSettings key="prc-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // Fixed assets (docs/modules/fixed-assets.md, Screens): the API answers 403 module_inactive without the module.
      ...[
        ['/fixed-assets/assets', FA_ASSET_VIEW, <FaAssets key="fa-assets" />],
        ['/fixed-assets/assets/new', 'fixed_assets.asset.manage', <FaAssetForm key="fa-asset-new" />],
        ['/fixed-assets/assets/:assetId', FA_ASSET_VIEW, <FaAssetDetail key="fa-asset" />],
        ['/fixed-assets/assets/:assetId/edit', 'fixed_assets.asset.manage', <FaAssetForm key="fa-asset-edit" />],
        ['/fixed-assets/categories', FA_ASSET_VIEW, <FaCategories key="fa-categories" />],
        ['/fixed-assets/runs', FA_DEPRECIATION_VIEW, <FaRuns key="fa-runs" />],
        ['/fixed-assets/runs/:runId', FA_DEPRECIATION_VIEW, <FaRunDetail key="fa-run" />],
        ['/fixed-assets/transfers', FA_ASSET_VIEW, <FaTransfers key="fa-transfers" />],
        ['/fixed-assets/transfers/:transferId', FA_ASSET_VIEW, <FaTransferDetail key="fa-transfer" />],
        ['/fixed-assets/disposals', FA_ASSET_VIEW, <FaDisposals key="fa-disposals" />],
        ['/fixed-assets/disposals/:disposalId', FA_ASSET_VIEW, <FaDisposalDetail key="fa-disposal" />],
        ['/fixed-assets/revaluations', FA_ASSET_VIEW, <FaRevaluations key="fa-revaluations" />],
        ['/fixed-assets/maintenance', FA_MAINTENANCE_VIEW, <FaMaintenance key="fa-maintenance" />],
        ['/fixed-assets/labels', FA_LABEL_PRINT, <FaLabels key="fa-labels" />],
        ['/fixed-assets/reports', FA_REPORT_VIEW, <FaReports key="fa-reports" />],
        ['/fixed-assets/settings', FA_SETTINGS_VIEW, <FaSettings key="fa-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // Stores (docs/modules/stores.md, Screens): the API answers 403 module_inactive without the module.
      ...[
        ['/stores/requisitions', STORES_REQUISITION_VIEW, <StoresRequisitions key="stores-requisitions" />],
        ['/stores/requisitions/new', 'stores.requisition.create', <StoresRequisitionForm key="stores-requisition-new" />],
        ['/stores/requisitions/:requisitionId', STORES_REQUISITION_VIEW, <StoresRequisitionDetail key="stores-requisition" />],
        ['/stores/issues', STORES_ISSUE_VIEW, <Issues key="stores-issues" />],
        ['/stores/issues/:issueId', STORES_ISSUE_VIEW, <IssueDetail key="stores-issue" />],
        ['/stores/returns', STORES_RETURN_VIEW, <Returns key="stores-returns" />],
        ['/stores/returns/:returnId', STORES_RETURN_VIEW, <ReturnDetail key="stores-return" />],
        ['/stores/ledger', STORES_LEDGER_VIEW, <StoreLedger key="stores-ledger" />],
        ['/stores/stock', STORES_LEDGER_VIEW, <StoreStock key="stores-stock" />],
        ['/stores/consumption', STORES_REPORT_VIEW, <Consumption key="stores-consumption" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // Manufacturing (docs/modules/manufacturing.md, Screens): the API answers 403 module_inactive without the module.
      ...[
        ['/manufacturing/boms', MANUFACTURING_BOM_VIEW, <Boms key="mfg-boms" />],
        ['/manufacturing/boms/new', 'manufacturing.bom.manage', <BomForm key="mfg-bom-new" />],
        ['/manufacturing/boms/:bomId', MANUFACTURING_BOM_VIEW, <BomDetail key="mfg-bom" />],
        ['/manufacturing/bom-versions/:versionId', MANUFACTURING_BOM_VIEW, <BomVersion key="mfg-bom-version" />],
        ['/manufacturing/orders', MANUFACTURING_ORDER_VIEW, <MfgOrders key="mfg-orders" />],
        ['/manufacturing/orders/new', 'manufacturing.order.create', <MfgOrderForm key="mfg-order-new" />],
        ['/manufacturing/orders/:orderId', MANUFACTURING_ORDER_VIEW, <MfgOrderDetail key="mfg-order" />],
        ['/manufacturing/orders/:orderId/edit', 'manufacturing.order.create', <MfgOrderForm key="mfg-order-edit" />],
        ['/manufacturing/consumption', MANUFACTURING_REPORT_VIEW, <MfgConsumption key="mfg-consumption" />],
        ['/manufacturing/yield', MANUFACTURING_REPORT_VIEW, <MfgYield key="mfg-yield" />],
        ['/manufacturing/backflushes', MANUFACTURING_REPORT_VIEW, <Backflushes key="mfg-backflushes" />],
        ['/manufacturing/recipe-costing', MANUFACTURING_REPORT_VIEW, <RecipeCosting key="mfg-recipe-costing" />],
        ['/manufacturing/settings', MANUFACTURING_SETTINGS_VIEW, <ManufacturingSettings key="mfg-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // HR (docs/modules/hr.md, Screens): the API answers 403 module_inactive without the module.
      ...[
        ['/hr/employees', HR_EMPLOYEE_VIEW, <Employees key="hr-employees" />],
        ['/hr/employees/new', 'hr.employee.create', <EmployeeForm key="hr-employee-new" />],
        ['/hr/employees/:employeeId', HR_EMPLOYEE_VIEW, <EmployeeProfile key="hr-employee" />],
        ['/hr/positions', HR_POSITION_VIEW, <Positions key="hr-positions" />],
        ['/hr/leave/requests', HR_LEAVE_VIEW, <LeaveRequests key="hr-leave-requests" />],
        ['/hr/leave/balances', HR_LEAVE_VIEW, <LeaveBalances key="hr-leave-balances" />],
        ['/hr/leave/calendar', HR_LEAVE_VIEW, <LeaveCalendar key="hr-leave-calendar" />],
        ['/hr/leave/types', HR_LEAVE_VIEW, <LeaveTypes key="hr-leave-types" />],
        ['/hr/rosters/week', HR_ROSTER_VIEW, <RosterWeek key="hr-roster-week" />],
        ['/hr/rosters/list', HR_ROSTER_VIEW, <RosterList key="hr-rosters" />],
        ['/hr/rosters/list/:rosterId', HR_ROSTER_VIEW, <RosterDetail key="hr-roster" />],
        ['/hr/rosters/setup', HR_ROSTER_VIEW, <RosterSetup key="hr-roster-setup" />],
        ['/hr/rosters/swaps', HR_ROSTER_VIEW, <HrSwaps key="hr-swaps" />],
        ['/hr/attendance/timesheets', 'hr.attendance.view', <Timesheets key="hr-timesheets" />],
        ['/hr/attendance/punches', 'hr.attendance.view', <HrPunches key="hr-punches" />],
        ['/hr/attendance/periods', 'hr.attendance.view', <HrPeriods key="hr-periods" />],
        ['/hr/attendance/rules', 'hr.attendance.view', <HrTimeRules key="hr-time-rules" />],
        ['/hr/attendance/devices', 'hr.device.manage', <HrDevices key="hr-devices" />],
        ['/hr/onboarding', HR_CHECKLIST_VIEW, <Onboarding key="hr-onboarding" />],
        ['/hr/disciplinary', HR_DISCIPLINARY_VIEW, <DisciplinaryCases key="hr-cases" />],
        ['/hr/disciplinary/:caseId', HR_DISCIPLINARY_VIEW, <CaseDetail key="hr-case" />],
        ['/hr/appraisals', HR_APPRAISAL_VIEW, <HrAppraisals key="hr-appraisals" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // A section's own path opens its first page the user may see.
      { path: '/hr/leave', element: <SectionIndex section="leave" /> },
      { path: '/hr/rosters', element: <SectionIndex section="rosters" /> },
      { path: '/hr/attendance', element: <SectionIndex section="attendance" /> },
      // HR-30: an appraisal's review; its reviewer may hold no HR role (the API decides).
      { path: '/hr/appraisals/:appraisalId', element: <AppraisalReview /> },
      // HR-31: self-service; being the linked employee is the authorisation (the API decides).
      { path: '/hr/me', element: <Navigate to="/hr/me/profile" replace /> },
      { path: '/hr/me/profile', element: <MyProfile /> },
      { path: '/hr/me/leave', element: <MyLeave /> },
      { path: '/hr/me/roster', element: <MyRoster /> },
      { path: '/hr/me/attendance', element: <MyAttendance /> },
      { path: '/hr/me/swaps', element: <MySwaps /> },
      { path: '/hr/me/appraisals', element: <MyAppraisals /> },
      // Payroll (docs/modules/payroll.md, Screens): the API answers 403 module_inactive without the module.
      ...[
        ['/payroll/runs', PAYROLL_RUN_VIEW, <PayrollRuns key="pay-runs" />],
        ['/payroll/runs/:runId', PAYROLL_RUN_VIEW, <PayrollRunDetail key="pay-run" />],
        ['/payroll/employees', PAYROLL_EMPLOYEE_VIEW, <PayEmployees key="pay-employees" />],
        ['/payroll/employees/:employeeId', PAYROLL_EMPLOYEE_VIEW, <PayEmployeeDetail key="pay-employee" />],
        ['/payroll/loans', PAYROLL_LOAN_VIEW, <Loans key="pay-loans" />],
        ['/payroll/loans/:loanId', PAYROLL_LOAN_VIEW, <LoanDetail key="pay-loan" />],
        ['/payroll/payslips', PAYROLL_PAYSLIP_VIEW, <Payslips key="pay-payslips" />],
        ['/payroll/payslips/:payslipId', PAYROLL_PAYSLIP_VIEW, <PayslipDetail key="pay-payslip" />],
        ['/payroll/returns', PAYROLL_RETURN_VIEW, <PayrollReturns key="pay-returns" />],
        ['/payroll/settings', PAYROLL_SETTINGS_VIEW, <PayrollSettings key="pay-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      { path: '/payroll', element: <Navigate to="/payroll/runs" replace /> },
      // PAY-24: "My payslips"; being the linked employee is the authorisation (the API decides).
      { path: '/payroll/me/payslips', element: <MyPayslips /> },
      { path: '/payroll/me/payslips/:payslipId', element: <MyPayslipDetail /> },
      // Accounting (docs/modules/accounting.md): the API answers 403 module_inactive without the module.
      ...[
        ['/accounting/chart', ACCOUNT_VIEW, <ChartOfAccounts key="acc-chart" />],
        ['/accounting/journals', JOURNAL_VIEW, <Journals key="acc-journals" />],
        ['/accounting/journals/new', 'accounting.journal.create', <JournalForm key="acc-journal-new" />],
        ['/accounting/journals/:journalId', JOURNAL_VIEW, <JournalDetail key="acc-journal" />],
        ['/accounting/journals/:journalId/edit', 'accounting.journal.create', <JournalForm key="acc-journal-edit" />],
        ['/accounting/documents', INVOICE_VIEW, <Documents key="acc-documents" />],
        ['/accounting/documents/new', 'accounting.invoice.create', <DocumentForm key="acc-document-new" />],
        ['/accounting/documents/:documentId', INVOICE_VIEW, <DocumentDetail key="acc-document" />],
        ['/accounting/payments', ACCOUNTING_PAYMENT_VIEW, <AccountingPayments key="acc-payments" />],
        ['/accounting/payments/:paymentId', ACCOUNTING_PAYMENT_VIEW, <PaymentDetail key="acc-payment" />],
        ['/accounting/bank', BANK_VIEW, <BankAccounts key="acc-bank" />],
        ['/accounting/bank/:bankId', BANK_VIEW, <Reconcile key="acc-reconcile" />],
        ['/accounting/budgets', BUDGET_VIEW, <Budgets key="acc-budgets" />],
        ['/accounting/budgets/:budgetId', BUDGET_VIEW, <BudgetDetail key="acc-budget" />],
        ['/accounting/periods', PERIOD_VIEW, <Periods key="acc-periods" />],
        ['/accounting/reports', REPORT_VIEW, <Reports key="acc-reports" />],
        ['/accounting/reports/ledger', 'accounting.report.view', <GeneralLedger key="acc-ledger" />],
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
        // IMP-01..IMP-03: imports, the wizard and one import where it stands.
        ['/settings/imports', IMPORT_PERMISSION, <Imports key="imports" />],
        ['/settings/imports/new', IMPORT_PERMISSION, <ImportWizard key="import-new" />],
        ['/settings/imports/:importId', IMPORT_PERMISSION, <ImportWizard key="import" />],
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
      // BR-02, BR-08: the theme editor; BR-04..BR-06: subdomain, custom domains, email and SMS senders.
      { path: '/settings/brand', element: <RequirePermission permission={THEME_VIEW}><Brand /></RequirePermission> },
      // LAY-05: the API answers 404 for the kind without the POS module (RBAC-08).
      { path: '/settings/pos-layout', handle: { wide: true }, element: <RequirePermission permission={POS_LAYOUT_VIEW}><PosLayout /></RequirePermission> },
      { path: '/settings/domains', element: <RequirePermission permission="core.domain.manage" tenantWide><Domains /></RequirePermission> },
      // API-02, API-03, API-05: API keys, their usage and the sandbox reset.
      { path: '/settings/api-keys', element: <RequirePermission permission="core.api_key.manage" tenantWide><ApiKeys /></RequirePermission> },
      // EXP-02: the full data export (Owners only; the API also checks the Owner role).
      // SUB-02..SUB-06: the plan, invoices and payment methods.
      { path: '/settings/subscription', element: <RequirePermission permission="core.subscription.view" tenantWide><Subscription /></RequirePermission> },
      // LIM-01, LIM-02: usage against the plan (Owners only).
      { path: '/settings/usage', element: <RequirePermission owner><Usage /></RequirePermission> },
      { path: '/settings/data-export', element: <RequirePermission permission="core.tenant.export" tenantWide><DataExport /></RequirePermission> },
      // HOOK-01: webhooks, their delivery log and the developer guide (Owner, Admin).
      { path: '/settings/webhooks', element: <RequirePermission permission="core.webhook.manage" tenantWide><Webhooks /></RequirePermission> },
      { path: '/settings/webhooks/:webhookId', element: <RequirePermission permission="core.webhook.manage" tenantWide><WebhookDetail /></RequirePermission> },
      { path: '/settings/numbering', element: <RequirePermission permission={NUMBERING_VIEW}><Numbering /></RequirePermission> },
      // AUTH-06: one's own POS PIN.
      { path: '/settings/pos-pin', element: <MyPosPin /> },
      { path: '/settings/appearance', element: <Appearance /> },
      // LAY-01, LAY-02: the organisation's and roles' dashboards and menus; anyone customises their own dashboard.
      { path: '/settings/layouts/dashboards', handle: { wide: true }, element: <RequirePermission permission={LAYOUT_VIEW} tenantWide><DashboardDesigner key="dashboards" /></RequirePermission> },
      { path: '/settings/layouts/navigation', handle: { wide: true }, element: <RequirePermission permission={LAYOUT_VIEW} tenantWide><NavigationEditor /></RequirePermission> },
      // CF-04, CF-05: the organisation's own forms: their records (the API decides who sees which), and the types (admins).
      { path: '/forms/:formKey', element: <FormRecords /> },
      { path: '/forms/:formKey/new', element: <FormRecordPage key="new-form-record" /> },
      { path: '/forms/:formKey/:recordId', element: <FormRecordPage /> },
      { path: '/settings/forms', element: <RequirePermission permission="core.custom_form_type.manage" tenantWide><FormTypes /></RequirePermission> },
      // LAY-03: the layouts of the item, party and custom forms, for the organisation and its roles.
      { path: '/settings/layouts/forms', handle: { wide: true }, element: <RequirePermission permission={LAYOUT_VIEW} tenantWide><FormLayoutDesigner /></RequirePermission> },
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
