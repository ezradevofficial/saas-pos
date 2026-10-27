import { lazy, Suspense } from 'react'
import { NotInReadOnlySession } from './auth/NotInReadOnlySession'
import { Navigate } from 'react-router'
import { GuestOnly, RequireAuth } from './auth/RequireAuth'
import { RequirePermission } from './auth/RequirePermission'
import { AppShell } from './layouts/AppShell'
import { KdsLayout } from './pages/restaurant/KdsLayout'
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
  POS_SALE_VOID,
  RESTAURANT_FLOOR_VIEW,
  RESTAURANT_MODIFIER_VIEW,
  RESTAURANT_ORDER_VIEW,
  RESTAURANT_REPORT_VIEW,
  RESTAURANT_SETTINGS_EDIT,
  RESTAURANT_STATION_VIEW,
  RESTAURANT_TICKET_VIEW,
  SALON_APPOINTMENT_VIEW,
  SALON_CLIENT_VIEW,
  SALON_COMMISSION_VIEW,
  SALON_REPORT_VIEW,
  SALON_SERVICE_VIEW,
  SALON_SETTINGS_VIEW,
  SALON_STAFF_VIEW,
  VAN_SALES_COLLECTION_VIEW,
  VAN_SALES_CREDIT_REVIEW,
  VAN_SALES_CREDIT_VIEW,
  VAN_SALES_LOAD_VIEW,
  VAN_SALES_RECONCILIATION_VIEW,
  VAN_SALES_REPORT_VIEW,
  VAN_SALES_ROUTE_VIEW,
  VAN_SALES_RUN_VIEW,
  VAN_SALES_SETTINGS_EDIT,
  VAN_SALES_VAN_VIEW,
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
import { OnboardingLayout } from './pages/onboarding/OnboardingLayout'
import { PAYROLL_EMPLOYEE_VIEW, PAYROLL_LOAN_VIEW, PAYROLL_PAYSLIP_VIEW, PAYROLL_RETURN_VIEW, PAYROLL_RUN_VIEW, PAYROLL_SETTINGS_VIEW } from './layouts/navigation'
import { PortalLayout } from './pages/procurement/portal/PortalLayout'
import { CREDIT_VIEW, GIFT_CARD_VIEW, PROGRAMME_VIEW, PROMOTION_VIEW, SALE_VIEW } from './pages/loyalty/loyaltyData'
import './pages/loyalty/register'
import {
  DISPENSE_REVIEW,
  ITEM_VIEW as PHARMACY_ITEM_VIEW,
  LOT_RECEIVE,
  LOT_TRANSFER,
  LOT_VIEW,
  PRESCRIPTION_VIEW,
  RECALL_VIEW,
  REGISTER_VIEW,
  REPORT_VIEW as PHARMACY_REPORT_VIEW,
  SETTINGS_VIEW as PHARMACY_SETTINGS_VIEW,
  WRITEOFF_CREATE,
  WRITEOFF_VIEW,
} from './pages/pharmacy/pharmacyData'
import {
  AUDIT_VIEW as HOTEL_AUDIT_VIEW,
  CHECKIN as HOTEL_CHECKIN,
  CHECKOUT as HOTEL_CHECKOUT,
  FOLIO_VIEW as HOTEL_FOLIO_VIEW,
  GUEST_VIEW as HOTEL_GUEST_VIEW,
  HK_VIEW as HOTEL_HK_VIEW,
  RATE_VIEW as HOTEL_RATE_VIEW,
  REPORT_VIEW as HOTEL_REPORT_VIEW,
  RESERVATION_CREATE as HOTEL_RESERVATION_CREATE,
  RESERVATION_VIEW as HOTEL_RESERVATION_VIEW,
  ROOM_VIEW as HOTEL_ROOM_VIEW,
  SETTINGS_VIEW as HOTEL_SETTINGS_VIEW,
} from './pages/hotel/hotelData'
import {
  DELIVERY_VIEW as FUEL_DELIVERY_VIEW,
  DIP_VIEW as FUEL_DIP_VIEW,
  PRICE_VIEW as FUEL_PRICE_VIEW,
  REPORT_VIEW as FUEL_REPORT_VIEW,
  SALE_VIEW as FUEL_SALE_VIEW,
  SETTINGS_VIEW as FUEL_SETTINGS_VIEW,
  SETUP_VIEW as FUEL_SETUP_VIEW,
  SHIFT_VIEW as FUEL_SHIFT_VIEW,
  VARIANCE_VIEW as FUEL_VARIANCE_VIEW,
  VEHICLE_VIEW as FUEL_VEHICLE_VIEW,
  VOUCHER_VIEW as FUEL_VOUCHER_VIEW,
} from './pages/fuel/fuelData'
import { ACTIVITY_VIEW as CRM_ACTIVITY_VIEW, LEAD_VIEW as CRM_LEAD_VIEW, OPPORTUNITY_VIEW as CRM_OPPORTUNITY_VIEW, QUOTE_VIEW as CRM_QUOTE_VIEW, SETTINGS_VIEW as CRM_SETTINGS_VIEW, TARGET_VIEW as CRM_TARGET_VIEW } from './pages/crm/crmData'
import './pages/crm/register'
import { CASHUP_VIEW as ECOM_CASHUP_VIEW, DELIVERY_VIEW as ECOM_DELIVERY_VIEW, ORDER_VIEW as ECOM_ORDER_VIEW, REPORT_VIEW as ECOM_REPORT_VIEW, RIDERS_VIEW as ECOM_RIDERS_VIEW, SETTINGS_VIEW as ECOM_SETTINGS_VIEW, WHATSAPP_VIEW as ECOM_WHATSAPP_VIEW } from './pages/ecommerce/ecommerceData'
import { ShopLayout } from './shop/ShopLayout'
import { ACCOUNT_VIEW, BANK_VIEW, BUDGET_VIEW, INVOICE_VIEW, JOURNAL_VIEW, PAYMENT_VIEW as ACCOUNTING_PAYMENT_VIEW, PERIOD_VIEW, REPORT_VIEW } from './pages/accounting/accountingData'
import { platformRoutes } from './platform/routes'
// The sign-in flow is the first screen: kept in the entry chunk.
import ForgotPassword from './pages/auth/ForgotPassword'
import ResetPassword from './pages/auth/ResetPassword'
import SignIn from './pages/auth/SignIn'
import SsoComplete from './pages/auth/SsoComplete'
import SignUp from './pages/auth/SignUp'
import TwoFactor from './pages/auth/TwoFactor'
import Verify from './pages/auth/Verify'
import Welcome from './pages/onboarding/Welcome'
import { DevelopersLayout } from './pages/developers/DevelopersLayout'

// NFR-02: every page is its own chunk, loaded when its route is first opened.
// The layouts (eager, above) render each page inside a Suspense boundary.
const PosLayout = lazy(() => import('./pages/settings/PosLayout'))
const AutomationRules = lazy(() => import('./pages/automation/AutomationRules'))
const RuleEditor = lazy(() => import('./pages/automation/RuleEditor'))
const AutomationRuns = lazy(() => import('./pages/automation/RunLog'))
const Approvals = lazy(() => import('./pages/approvals/Approvals'))
const EmailApproval = lazy(() => import('./pages/approvals/EmailApproval'))
const OptOut = lazy(() => import('./pages/privacy/OptOut'))
const AcceptInvitation = lazy(() => import('./pages/auth/AcceptInvitation'))
const TwoFactorEnrol = lazy(() => import('./pages/auth/TwoFactorEnrol'))
const Categories = lazy(() => import('./pages/catalogue/Categories'))
const ItemDetail = lazy(() => import('./pages/catalogue/ItemDetail'))
const NewItem = lazy(() => import('./pages/catalogue/ItemForm'))
const Items = lazy(() => import('./pages/catalogue/Items'))
const Units = lazy(() => import('./pages/catalogue/Units'))
const CreditLimitChanges = lazy(() => import('./pages/contacts/CreditLimitChanges'))
const Parties = lazy(() => import('./pages/contacts/Parties'))
const PartyDetail = lazy(() => import('./pages/contacts/PartyDetail'))
const NewParty = lazy(() => import('./pages/contacts/PartyForm'))
const FormRecordPage = lazy(() => import('./pages/forms/FormRecordPage'))
const FormRecords = lazy(() => import('./pages/forms/FormRecords'))
const FormTypes = lazy(() => import('./pages/forms/FormTypes'))
const Home = lazy(() => import('./pages/Home'))
const BusinessType = lazy(() => import('./pages/onboarding/BusinessType'))
const ModulePicker = lazy(() => import('./pages/onboarding/ModulePicker'))
const SetupOverview = lazy(() => import('./pages/onboarding/Setup').then((m) => ({ default: m.SetupOverview })))
const SetupStep = lazy(() => import('./pages/onboarding/Setup').then((m) => ({ default: m.SetupStep })))
const Inbox = lazy(() => import('./pages/notifications/Inbox'))
const AdjustmentDetail = lazy(() => import('./pages/inventory/AdjustmentDetail'))
const AdjustmentForm = lazy(() => import('./pages/inventory/AdjustmentForm'))
const Adjustments = lazy(() => import('./pages/inventory/Adjustments'))
const CountDetail = lazy(() => import('./pages/inventory/CountDetail'))
const CountForm = lazy(() => import('./pages/inventory/CountForm'))
const Counts = lazy(() => import('./pages/inventory/Counts'))
const InventorySettings = lazy(() => import('./pages/inventory/InventorySettings'))
const ItemStock = lazy(() => import('./pages/inventory/ItemStock'))
const Labels = lazy(() => import('./pages/inventory/Labels'))
const Movements = lazy(() => import('./pages/inventory/Movements'))
const ReorderRules = lazy(() => import('./pages/inventory/ReorderRules'))
const StockOnHand = lazy(() => import('./pages/inventory/StockOnHand'))
const TransferDetail = lazy(() => import('./pages/inventory/TransferDetail'))
const TransferForm = lazy(() => import('./pages/inventory/TransferForm'))
const Transfers = lazy(() => import('./pages/inventory/Transfers'))
const Valuation = lazy(() => import('./pages/inventory/Valuation'))
const BomDetail = lazy(() => import('./pages/manufacturing/BomDetail'))
const BomForm = lazy(() => import('./pages/manufacturing/BomForm'))
const Boms = lazy(() => import('./pages/manufacturing/Boms'))
const BomVersion = lazy(() => import('./pages/manufacturing/BomVersion'))
const Backflushes = lazy(() => import('./pages/manufacturing/Backflushes'))
const MfgConsumption = lazy(() => import('./pages/manufacturing/Consumption'))
const ManufacturingSettings = lazy(() => import('./pages/manufacturing/ManufacturingSettings'))
const MfgOrderDetail = lazy(() => import('./pages/manufacturing/OrderDetail'))
const MfgOrderForm = lazy(() => import('./pages/manufacturing/OrderForm'))
const MfgOrders = lazy(() => import('./pages/manufacturing/Orders'))
const RestaurantFloor = lazy(() => import('./pages/restaurant/FloorPlan'))
const RestaurantKitchen = lazy(() => import('./pages/restaurant/KitchenDisplay'))
const RestaurantModifiers = lazy(() => import('./pages/restaurant/Modifiers'))
const RestaurantOrders = lazy(() => import('./pages/restaurant/Orders'))
const RestaurantPendingVoids = lazy(() => import('./pages/restaurant/PendingVoids'))
const RestaurantReports = lazy(() => import('./pages/restaurant/Reports'))
const RestaurantSettings = lazy(() => import('./pages/restaurant/RestaurantSettings'))
const RestaurantStations = lazy(() => import('./pages/restaurant/Stations'))
const SalonCalendar = lazy(() => import('./pages/salon/Calendar'))
const SalonServices = lazy(() => import('./pages/salon/Services'))
const SalonStaff = lazy(() => import('./pages/salon/StaffPage'))
const SalonClients = lazy(() => import('./pages/salon/Clients'))
const SalonCommissions = lazy(() => import('./pages/salon/Commissions'))
const SalonReports = lazy(() => import('./pages/salon/Reports'))
const SalonSettings = lazy(() => import('./pages/salon/SalonSettings'))
const SalonPublicBooking = lazy(() => import('./pages/salon/PublicBooking'))
const VanSalesVans = lazy(() => import('./pages/vansales/Vans'))
const VanSalesRoutes = lazy(() => import('./pages/vansales/Routes'))
const VanSalesRouteDetail = lazy(() => import('./pages/vansales/RouteDetail'))
const VanSalesPlan = lazy(() => import('./pages/vansales/Plan'))
const VanSalesLoads = lazy(() => import('./pages/vansales/Loads'))
const VanSalesRuns = lazy(() => import('./pages/vansales/Runs'))
const VanSalesRunDetail = lazy(() => import('./pages/vansales/RunDetail'))
const VanSalesReconciliations = lazy(() => import('./pages/vansales/Reconciliations'))
const VanSalesReconciliationDetail = lazy(() => import('./pages/vansales/ReconciliationDetail'))
const VanSalesAccounts = lazy(() => import('./pages/vansales/Accounts').then((module) => ({ default: module.Accounts })))
const VanSalesAccountDetail = lazy(() => import('./pages/vansales/Accounts').then((module) => ({ default: module.AccountDetail })))
const VanSalesCreditReviews = lazy(() => import('./pages/vansales/CreditReviews'))
const VanSalesCollections = lazy(() => import('./pages/vansales/Collections'))
const VanSalesReports = lazy(() => import('./pages/vansales/Reports'))
const VanSalesSettings = lazy(() => import('./pages/vansales/VanSalesSettings'))
const RecipeCosting = lazy(() => import('./pages/manufacturing/RecipeCosting'))
const MfgYield = lazy(() => import('./pages/manufacturing/Yield'))
const FaAssetDetail = lazy(() => import('./pages/fixedAssets/AssetDetail'))
const FaAssetForm = lazy(() => import('./pages/fixedAssets/AssetForm'))
const FaAssets = lazy(() => import('./pages/fixedAssets/Assets'))
const FaCategories = lazy(() => import('./pages/fixedAssets/Categories'))
const FaLabels = lazy(() => import('./pages/fixedAssets/Labels'))
const FaMaintenance = lazy(() => import('./pages/fixedAssets/Maintenance'))
const FaDisposalDetail = lazy(() => import('./pages/fixedAssets/Movements').then((m) => ({ default: m.DisposalDetail })))
const FaDisposals = lazy(() => import('./pages/fixedAssets/Movements').then((m) => ({ default: m.Disposals })))
const FaRevaluations = lazy(() => import('./pages/fixedAssets/Movements').then((m) => ({ default: m.Revaluations })))
const FaTransferDetail = lazy(() => import('./pages/fixedAssets/Movements').then((m) => ({ default: m.TransferDetail })))
const FaTransfers = lazy(() => import('./pages/fixedAssets/Movements').then((m) => ({ default: m.Transfers })))
const FaReports = lazy(() => import('./pages/fixedAssets/Reports'))
const FaRuns = lazy(() => import('./pages/fixedAssets/Runs'))
const FaRunDetail = lazy(() => import('./pages/fixedAssets/Runs').then((m) => ({ default: m.RunDetail })))
const FaSettings = lazy(() => import('./pages/fixedAssets/Settings'))
const Consumption = lazy(() => import('./pages/stores/Consumption'))
const IssueDetail = lazy(() => import('./pages/stores/IssueDetail'))
const Issues = lazy(() => import('./pages/stores/Issues'))
const StoresRequisitionDetail = lazy(() => import('./pages/stores/RequisitionDetail'))
const StoresRequisitionForm = lazy(() => import('./pages/stores/RequisitionForm'))
const StoresRequisitions = lazy(() => import('./pages/stores/Requisitions'))
const ReturnDetail = lazy(() => import('./pages/stores/ReturnDetail'))
const Returns = lazy(() => import('./pages/stores/Returns'))
const StoreLedger = lazy(() => import('./pages/stores/StoreLedger'))
const StoreStock = lazy(() => import('./pages/stores/StoreStock'))
const Timesheets = lazy(() => import('./pages/hr/Attendance'))
const HrDevices = lazy(() => import('./pages/hr/Attendance').then((m) => ({ default: m.Devices })))
const HrPeriods = lazy(() => import('./pages/hr/Attendance').then((m) => ({ default: m.Periods })))
const HrPunches = lazy(() => import('./pages/hr/Attendance').then((m) => ({ default: m.Punches })))
const HrTimeRules = lazy(() => import('./pages/hr/Attendance').then((m) => ({ default: m.TimeRules })))
const EmployeeProfile = lazy(() => import('./pages/hr/EmployeeProfile'))
const Employees = lazy(() => import('./pages/hr/Employees'))
const EmployeeForm = lazy(() => import('./pages/hr/Employees').then((m) => ({ default: m.EmployeeForm })))
const SectionIndex = lazy(() => import('./pages/hr/HrParts').then((m) => ({ default: m.SectionIndex })))
const LeaveRequests = lazy(() => import('./pages/hr/Leave'))
const LeaveBalances = lazy(() => import('./pages/hr/Leave').then((m) => ({ default: m.LeaveBalances })))
const LeaveCalendar = lazy(() => import('./pages/hr/Leave').then((m) => ({ default: m.LeaveCalendar })))
const LeaveTypes = lazy(() => import('./pages/hr/Leave').then((m) => ({ default: m.LeaveTypes })))
const MyAppraisals = lazy(() => import('./pages/hr/MyHr').then((m) => ({ default: m.MyAppraisals })))
const MyAttendance = lazy(() => import('./pages/hr/MyHr').then((m) => ({ default: m.MyAttendance })))
const MyLeave = lazy(() => import('./pages/hr/MyHr').then((m) => ({ default: m.MyLeave })))
const MyProfile = lazy(() => import('./pages/hr/MyHr').then((m) => ({ default: m.MyProfile })))
const MyRoster = lazy(() => import('./pages/hr/MyHr').then((m) => ({ default: m.MyRoster })))
const MySwaps = lazy(() => import('./pages/hr/MyHr').then((m) => ({ default: m.MySwaps })))
const Positions = lazy(() => import('./pages/hr/Positions'))
const PayEmployees = lazy(() => import('./pages/payroll/Employees'))
const PayEmployeeDetail = lazy(() => import('./pages/payroll/Employees').then((m) => ({ default: m.PayEmployeeDetail })))
const Loans = lazy(() => import('./pages/payroll/Loans'))
const LoanDetail = lazy(() => import('./pages/payroll/Loans').then((m) => ({ default: m.LoanDetail })))
const Payslips = lazy(() => import('./pages/payroll/Payslips'))
const MyPayslipDetail = lazy(() => import('./pages/payroll/Payslips').then((m) => ({ default: m.MyPayslipDetail })))
const MyPayslips = lazy(() => import('./pages/payroll/Payslips').then((m) => ({ default: m.MyPayslips })))
const PayslipDetail = lazy(() => import('./pages/payroll/Payslips').then((m) => ({ default: m.PayslipDetail })))
const PayrollReturns = lazy(() => import('./pages/payroll/Returns'))
const PayrollRuns = lazy(() => import('./pages/payroll/Runs'))
const PayrollRunDetail = lazy(() => import('./pages/payroll/Runs').then((m) => ({ default: m.RunDetail })))
const PayrollSettings = lazy(() => import('./pages/payroll/Settings'))
const AppraisalReview = lazy(() => import('./pages/hr/Records').then((m) => ({ default: m.AppraisalReview })))
const HrAppraisals = lazy(() => import('./pages/hr/Records').then((m) => ({ default: m.Appraisals })))
const CaseDetail = lazy(() => import('./pages/hr/Records').then((m) => ({ default: m.CaseDetail })))
const DisciplinaryCases = lazy(() => import('./pages/hr/Records').then((m) => ({ default: m.DisciplinaryCases })))
const Onboarding = lazy(() => import('./pages/hr/Records').then((m) => ({ default: m.Onboarding })))
const RosterWeek = lazy(() => import('./pages/hr/Rosters'))
const RosterDetail = lazy(() => import('./pages/hr/Rosters').then((m) => ({ default: m.RosterDetail })))
const RosterList = lazy(() => import('./pages/hr/Rosters').then((m) => ({ default: m.RosterList })))
const RosterSetup = lazy(() => import('./pages/hr/Rosters').then((m) => ({ default: m.RosterSetup })))
const HrSwaps = lazy(() => import('./pages/hr/Rosters').then((m) => ({ default: m.Swaps })))
const NotFound = lazy(() => import('./pages/NotFound'))
const InvoiceDetail = lazy(() => import('./pages/procurement/InvoiceDetail'))
const InvoiceForm = lazy(() => import('./pages/procurement/InvoiceForm'))
const LandedCostDetail = lazy(() => import('./pages/procurement/LandedCostDetail'))
const LandedCostForm = lazy(() => import('./pages/procurement/LandedCostForm'))
const OrderDetail = lazy(() => import('./pages/procurement/OrderDetail'))
const OrderForm = lazy(() => import('./pages/procurement/OrderForm'))
const ProcurementInvoices = lazy(() => import('./pages/procurement/ProcurementLists').then((m) => ({ default: m.Invoices })))
const LandedCosts = lazy(() => import('./pages/procurement/ProcurementLists').then((m) => ({ default: m.LandedCosts })))
const Orders = lazy(() => import('./pages/procurement/ProcurementLists').then((m) => ({ default: m.Orders })))
const Receipts = lazy(() => import('./pages/procurement/ProcurementLists').then((m) => ({ default: m.Receipts })))
const Requisitions = lazy(() => import('./pages/procurement/ProcurementLists').then((m) => ({ default: m.Requisitions })))
const Rfqs = lazy(() => import('./pages/procurement/ProcurementLists').then((m) => ({ default: m.Rfqs })))
const Tenders = lazy(() => import('./pages/procurement/ProcurementLists').then((m) => ({ default: m.Tenders })))
const ProcurementSettings = lazy(() => import('./pages/procurement/ProcurementSettings'))
const ReceiptDetail = lazy(() => import('./pages/procurement/ReceiptDetail'))
const ReceiptForm = lazy(() => import('./pages/procurement/ReceiptForm'))
const RequisitionDetail = lazy(() => import('./pages/procurement/RequisitionDetail'))
const RequisitionForm = lazy(() => import('./pages/procurement/RequisitionForm'))
const RfqDetail = lazy(() => import('./pages/procurement/RfqDetail'))
const RfqForm = lazy(() => import('./pages/procurement/RfqForm'))
const SupplierPerformance = lazy(() => import('./pages/procurement/SupplierPerformance'))
const TenderDetail = lazy(() => import('./pages/procurement/TenderDetail'))
const TenderForm = lazy(() => import('./pages/procurement/TenderForm'))
const PortalNotice = lazy(() => import('./pages/procurement/portal/PortalPages').then((m) => ({ default: m.PortalNotice })))
const PortalSignIn = lazy(() => import('./pages/procurement/portal/PortalPages').then((m) => ({ default: m.PortalSignIn })))
const PortalTender = lazy(() => import('./pages/procurement/portal/PortalPages').then((m) => ({ default: m.PortalTender })))
const Ageing = lazy(() => import('./pages/loyalty/Ageing'))
const CreditAccountDetail = lazy(() => import('./pages/loyalty/CreditAccountDetail'))
const CreditAccounts = lazy(() => import('./pages/loyalty/CreditAccounts'))
const GiftCardDetail = lazy(() => import('./pages/loyalty/GiftCardDetail'))
const GiftCards = lazy(() => import('./pages/loyalty/GiftCards'))
const FlaggedSales = lazy(() => import('./pages/loyalty/FlaggedSales'))
const LoyaltyProgramme = lazy(() => import('./pages/loyalty/Programme'))
const PromotionEditor = lazy(() => import('./pages/loyalty/PromotionEditor'))
const Promotions = lazy(() => import('./pages/loyalty/Promotions'))
const ExpiryReport = lazy(() => import('./pages/pharmacy/ExpiryReport'))
const LotDetail = lazy(() => import('./pages/pharmacy/LotDetail'))
const Lots = lazy(() => import('./pages/pharmacy/Lots'))
const MoveStock = lazy(() => import('./pages/pharmacy/MoveStock'))
const PharmacyDispenses = lazy(() => import('./pages/pharmacy/Dispenses'))
const PharmacyItemProfiles = lazy(() => import('./pages/pharmacy/ItemProfiles'))
const PharmacyPrescriptionDetail = lazy(() => import('./pages/pharmacy/PrescriptionDetail'))
const PharmacyPrescriptionLog = lazy(() => import('./pages/pharmacy/PrescriptionLog'))
const PharmacyPrescriptions = lazy(() => import('./pages/pharmacy/Prescriptions'))
const PharmacyRecallDetail = lazy(() => import('./pages/pharmacy/RecallDetail'))
const PharmacyRecalls = lazy(() => import('./pages/pharmacy/Recalls'))
const PharmacyRegister = lazy(() => import('./pages/pharmacy/Register'))
const PharmacySettings = lazy(() => import('./pages/pharmacy/PharmacySettings'))
const ReceiveStock = lazy(() => import('./pages/pharmacy/ReceiveStock'))
const SlowMovers = lazy(() => import('./pages/pharmacy/SlowMovers'))
const WriteOffDetail = lazy(() => import('./pages/pharmacy/WriteOffDetail'))
const WriteOffForm = lazy(() => import('./pages/pharmacy/WriteOffForm'))
const WriteOffs = lazy(() => import('./pages/pharmacy/WriteOffs'))
const HotelAvailability = lazy(() => import('./pages/hotel/Availability'))
const HotelChargeExceptions = lazy(() => import('./pages/hotel/ChargeExceptions'))
const HotelFolioDetail = lazy(() => import('./pages/hotel/FolioDetail'))
const HotelFolios = lazy(() => import('./pages/hotel/Folios'))
const HotelFrontDesk = lazy(() => import('./pages/hotel/FrontDesk'))
const HotelGuestDetail = lazy(() => import('./pages/hotel/Guests').then((module) => ({ default: module.GuestDetail })))
const HotelGuests = lazy(() => import('./pages/hotel/Guests'))
const HotelHousekeeping = lazy(() => import('./pages/hotel/Housekeeping'))
const HotelNewReservation = lazy(() => import('./pages/hotel/NewReservation'))
const HotelNightAudit = lazy(() => import('./pages/hotel/NightAudit'))
const HotelRatePlans = lazy(() => import('./pages/hotel/RatePlans'))
const HotelReports = lazy(() => import('./pages/hotel/Reports'))
const HotelReservationDetail = lazy(() => import('./pages/hotel/ReservationDetail'))
const HotelReservations = lazy(() => import('./pages/hotel/Reservations'))
const HotelRooms = lazy(() => import('./pages/hotel/Rooms'))
const HotelSettings = lazy(() => import('./pages/hotel/HotelSettings'))
const FuelDeliveries = lazy(() => import('./pages/fuel/Deliveries'))
const FuelDeliveryDetail = lazy(() => import('./pages/fuel/DeliveryDetail'))
const FuelDips = lazy(() => import('./pages/fuel/Dips'))
const FuelMeterResets = lazy(() => import('./pages/fuel/MeterResets'))
const FuelPrices = lazy(() => import('./pages/fuel/Prices'))
const FuelReconciliationDetail = lazy(() => import('./pages/fuel/ReconciliationDetail'))
const FuelReconciliations = lazy(() => import('./pages/fuel/Reconciliations'))
const FuelReports = lazy(() => import('./pages/fuel/Reports'))
const FuelSales = lazy(() => import('./pages/fuel/Sales'))
const FuelSettlementDetail = lazy(() => import('./pages/fuel/SettlementDetail'))
const FuelSettlements = lazy(() => import('./pages/fuel/Settlements'))
const FuelSetup = lazy(() => import('./pages/fuel/Setup'))
const FuelShiftDetail = lazy(() => import('./pages/fuel/ShiftDetail'))
const FuelShifts = lazy(() => import('./pages/fuel/Shifts'))
const FuelTankDetail = lazy(() => import('./pages/fuel/TankDetail'))
const FuelVehicles = lazy(() => import('./pages/fuel/Vehicles'))
const FuelVoucherDetail = lazy(() => import('./pages/fuel/VoucherDetail'))
const FuelVouchers = lazy(() => import('./pages/fuel/Vouchers'))
const CrmCalendar = lazy(() => import('./pages/crm/Calendar'))
const CrmSettings = lazy(() => import('./pages/crm/CrmSettings'))
const Leads = lazy(() => import('./pages/crm/Leads'))
const LeadDetail = lazy(() => import('./pages/crm/Leads').then((m) => ({ default: m.LeadDetail })))
const LeadForm = lazy(() => import('./pages/crm/Leads').then((m) => ({ default: m.LeadForm })))
const Opportunities = lazy(() => import('./pages/crm/Opportunities'))
const OpportunityDetail = lazy(() => import('./pages/crm/Opportunities').then((m) => ({ default: m.OpportunityDetail })))
const OpportunityForm = lazy(() => import('./pages/crm/Opportunities').then((m) => ({ default: m.OpportunityForm })))
const Quotes = lazy(() => import('./pages/crm/Quotes'))
const QuoteDetail = lazy(() => import('./pages/crm/Quotes').then((m) => ({ default: m.QuoteDetail })))
const QuoteEditor = lazy(() => import('./pages/crm/Quotes').then((m) => ({ default: m.QuoteEditor })))
const CrmTargets = lazy(() => import('./pages/crm/Targets'))
const CashupsPage = lazy(() => import('./pages/ecommerce/Delivery').then((m) => ({ default: m.Cashups })))
const DeliveriesPage = lazy(() => import('./pages/ecommerce/Delivery').then((m) => ({ default: m.Deliveries })))
const RidersPage = lazy(() => import('./pages/ecommerce/Delivery').then((m) => ({ default: m.Riders })))
const EcomInbox = lazy(() => import('./pages/ecommerce/Inbox'))
const EcomOrders = lazy(() => import('./pages/ecommerce/Orders'))
const EcomOrderDetail = lazy(() => import('./pages/ecommerce/Orders').then((m) => ({ default: m.OrderDetail })))
const EcomReports = lazy(() => import('./pages/ecommerce/Reports'))
const EcomStoreSettings = lazy(() => import('./pages/ecommerce/StoreSettings'))
const ShopAccount = lazy(() => import('./shop/ShopPages').then((m) => ({ default: m.ShopAccount })))
const ShopCart = lazy(() => import('./shop/ShopPages').then((m) => ({ default: m.ShopCart })))
const ShopCheckout = lazy(() => import('./shop/ShopPages').then((m) => ({ default: m.ShopCheckout })))
const ShopHome = lazy(() => import('./shop/ShopPages').then((m) => ({ default: m.ShopHome })))
const ShopOrder = lazy(() => import('./shop/ShopPages').then((m) => ({ default: m.ShopOrder })))
const ShopProduct = lazy(() => import('./shop/ShopPages').then((m) => ({ default: m.ShopProduct })))
const Held = lazy(() => import('./pages/pos/Held'))
const SaleDetail = lazy(() => import('./pages/pos/SaleDetail'))
const Sales = lazy(() => import('./pages/pos/Sales'))
const SalesDashboard = lazy(() => import('./pages/pos/SalesDashboard'))
const ShiftDetail = lazy(() => import('./pages/pos/ShiftDetail'))
const Shifts = lazy(() => import('./pages/pos/Shifts'))
const BankAccounts = lazy(() => import('./pages/accounting/Bank'))
const Reconcile = lazy(() => import('./pages/accounting/Bank').then((m) => ({ default: m.Reconcile })))
const Budgets = lazy(() => import('./pages/accounting/Budgets'))
const BudgetDetail = lazy(() => import('./pages/accounting/Budgets').then((m) => ({ default: m.BudgetDetail })))
const ChartOfAccounts = lazy(() => import('./pages/accounting/ChartOfAccounts'))
const DocumentDetail = lazy(() => import('./pages/accounting/DocumentDetail'))
const DocumentForm = lazy(() => import('./pages/accounting/DocumentForm'))
const Documents = lazy(() => import('./pages/accounting/Documents'))
const JournalDetail = lazy(() => import('./pages/accounting/JournalDetail'))
const JournalForm = lazy(() => import('./pages/accounting/JournalForm'))
const Journals = lazy(() => import('./pages/accounting/Journals'))
const AccountingPayments = lazy(() => import('./pages/accounting/Payments'))
const PaymentDetail = lazy(() => import('./pages/accounting/Payments').then((m) => ({ default: m.PaymentDetail })))
const Periods = lazy(() => import('./pages/accounting/Periods'))
const Reports = lazy(() => import('./pages/accounting/Reports'))
const GeneralLedger = lazy(() => import('./pages/accounting/Reports').then((m) => ({ default: m.GeneralLedger })))
const Appearance = lazy(() => import('./pages/settings/Appearance'))
const Brand = lazy(() => import('./pages/settings/Brand'))
const DashboardDesigner = lazy(() => import('./pages/settings/layouts/DashboardDesigner'))
const FormLayoutDesigner = lazy(() => import('./pages/settings/layouts/FormLayoutDesigner'))
const NavigationEditor = lazy(() => import('./pages/settings/layouts/NavigationEditor'))
const Currencies = lazy(() => import('./pages/settings/Currencies'))
const CustomFields = lazy(() => import('./pages/settings/CustomFields'))
const DocumentTemplates = lazy(() => import('./pages/settings/DocumentTemplates'))
const TemplateDesigner = lazy(() => import('./pages/settings/templates/TemplateDesigner'))
const Dimensions = lazy(() => import('./pages/settings/Dimensions'))
const DataExport = lazy(() => import('./pages/settings/DataExport'))
const WebhookDetail = lazy(() => import('./pages/settings/webhooks/WebhookDetail'))
const Webhooks = lazy(() => import('./pages/settings/webhooks/Webhooks'))
const Privacy = lazy(() => import('./pages/settings/privacy/Privacy'))
const SingleSignOn = lazy(() => import('./pages/settings/SingleSignOn'))
const ApiKeys = lazy(() => import('./pages/settings/ApiKeys'))
// MKT-01, MKT-02: the marketplace, the consent screen, installed apps and developer apps.
const Marketplace = lazy(() => import('./pages/marketplace/Marketplace'))
const MarketplaceApp = lazy(() => import('./pages/marketplace/Marketplace').then((m) => ({ default: m.MarketplaceApp })))
const Consent = lazy(() => import('./pages/marketplace/Consent'))
const InstalledApps = lazy(() => import('./pages/settings/apps/InstalledApps'))
const InstalledApp = lazy(() => import('./pages/settings/apps/InstalledApps').then((m) => ({ default: m.InstalledApp })))
const DeveloperApps = lazy(() => import('./pages/settings/apps/DeveloperApps'))
const DeveloperApp = lazy(() => import('./pages/settings/apps/DeveloperApp'))
const ApiReference = lazy(() => import('./pages/developers/ApiReference').then((m) => ({ default: m.ApiReference })))
const Changelog = lazy(() => import('./pages/developers/DeveloperPages').then((m) => ({ default: m.Changelog })))
const DevelopersHome = lazy(() => import('./pages/developers/DeveloperPages').then((m) => ({ default: m.DevelopersHome })))
const Guide = lazy(() => import('./pages/developers/DeveloperPages').then((m) => ({ default: m.Guide })))
const SupportAccess = lazy(() => import('./pages/settings/SupportAccess'))
const SupportEnter = lazy(() => import('./pages/auth/SupportEnter'))
const Subscription = lazy(() => import('./pages/settings/Subscription'))
const Imports = lazy(() => import('./pages/settings/Imports'))
const ImportWizard = lazy(() => import('./pages/settings/imports/ImportWizard'))
const Domains = lazy(() => import('./pages/settings/Domains'))
const ExchangeRates = lazy(() => import('./pages/settings/ExchangeRates'))
const Fiscal = lazy(() => import('./pages/settings/Fiscal'))
const InviteUser = lazy(() => import('./pages/settings/InviteUser'))
const MasterDataSharing = lazy(() => import('./pages/settings/MasterDataSharing'))
const MyPosPin = lazy(() => import('./pages/settings/MyPosPin'))
const NotificationDeliveries = lazy(() => import('./pages/settings/NotificationDeliveries'))
const NotificationPreferences = lazy(() => import('./pages/settings/NotificationPreferences'))
const NotificationTemplates = lazy(() => import('./pages/settings/NotificationTemplates'))
const Numbering = lazy(() => import('./pages/settings/Numbering'))
const Organisation = lazy(() => import('./pages/settings/Organisation'))
const PaymentMethods = lazy(() => import('./pages/settings/PaymentMethods'))
const Payments = lazy(() => import('./pages/settings/Payments'))
const RoleDetail = lazy(() => import('./pages/settings/RoleDetail'))
const Roles = lazy(() => import('./pages/settings/Roles'))
const Security = lazy(() => import('./pages/settings/Security'))
const Sessions = lazy(() => import('./pages/settings/Sessions'))
const Taxes = lazy(() => import('./pages/settings/Taxes'))
const Usage = lazy(() => import('./pages/settings/Usage'))
const PriceListDetail = lazy(() => import('./pages/settings/taxes/PriceListDetail'))
const UserDetail = lazy(() => import('./pages/settings/UserDetail'))
const Users = lazy(() => import('./pages/settings/Users'))
const DocumentWorkflow = lazy(() => import('./pages/workflows/DocumentWorkflow'))
const WorkflowBuilder = lazy(() => import('./pages/workflows/LazyWorkflowBuilder'))
const WorkflowInsights = lazy(() => import('./pages/workflows/WorkflowInsights'))
const Workflows = lazy(() => import('./pages/workflows/Workflows'))

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
      // AUTH-11: the identity provider's callback lands here with a ticket in the fragment.
      { path: '/sso/complete', element: <SsoComplete /> },
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
      // NFR-08: a marketing email's unsubscribe link, no sign-in needed.
      { path: '/opt-out/:token', element: <OptOut /> },
      // RBAC-13c: the console's one-time code becomes a support session here.
      { path: '/support/enter', element: <SupportEnter /> },
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
  // SAL-15: a salon branch's public booking page, open to everyone; it never uses a tenant user's sign-in.
  { path: '/book/:code', element: <SalonPublicBooking /> },
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
  // MKT-02, API-02: the OAuth consent screen an app sends the user to (signed in; outside the shell).
  {
    element: (
      <RequireAuth>
        <AuthLayout />
      </RequireAuth>
    ),
    children: [{ path: '/oauth/authorize', element: <Consent /> }],
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
  // REST-12: the kitchen display fills the screen, outside the app shell (the API answers 403 module_inactive without the module).
  {
    element: (
      <RequireAuth>
        <KdsLayout />
      </RequireAuth>
    ),
    children: [{ path: '/restaurant/kitchen', element: <RequirePermission permission={RESTAURANT_TICKET_VIEW}><RestaurantKitchen /></RequirePermission> }],
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
      // Pharmacy (docs/modules/pharmacy.md): the API answers 403 module_inactive without the module.
      ...[
        ['/pharmacy/lots', LOT_VIEW, <Lots key="pharmacy-lots" />],
        ['/pharmacy/lots/:lotId', LOT_VIEW, <LotDetail key="pharmacy-lot" />],
        ['/pharmacy/receive', LOT_RECEIVE, <ReceiveStock key="pharmacy-receive" />],
        ['/pharmacy/transfers/new', LOT_TRANSFER, <MoveStock key="pharmacy-move" />],
        ['/pharmacy/write-offs', WRITEOFF_VIEW, <WriteOffs key="pharmacy-write-offs" />],
        ['/pharmacy/write-offs/new', WRITEOFF_CREATE, <WriteOffForm key="pharmacy-write-off-new" />],
        ['/pharmacy/write-offs/:writeOffId', WRITEOFF_VIEW, <WriteOffDetail key="pharmacy-write-off" />],
        ['/pharmacy/recalls', RECALL_VIEW, <PharmacyRecalls key="pharmacy-recalls" />],
        ['/pharmacy/recalls/:recallId', RECALL_VIEW, <PharmacyRecallDetail key="pharmacy-recall" />],
        ['/pharmacy/prescriptions', PRESCRIPTION_VIEW, <PharmacyPrescriptions key="pharmacy-prescriptions" />],
        ['/pharmacy/prescriptions/:prescriptionId', PRESCRIPTION_VIEW, <PharmacyPrescriptionDetail key="pharmacy-prescription" />],
        ['/pharmacy/register', REGISTER_VIEW, <PharmacyRegister key="pharmacy-register" />],
        ['/pharmacy/dispenses', DISPENSE_REVIEW, <PharmacyDispenses key="pharmacy-dispenses" />],
        ['/pharmacy/reports/expiry', PHARMACY_REPORT_VIEW, <ExpiryReport key="pharmacy-expiry" />],
        ['/pharmacy/reports/slow-movers', PHARMACY_REPORT_VIEW, <SlowMovers key="pharmacy-slow" />],
        ['/pharmacy/reports/prescriptions', PHARMACY_REPORT_VIEW, <PharmacyPrescriptionLog key="pharmacy-log" />],
        ['/pharmacy/items', PHARMACY_ITEM_VIEW, <PharmacyItemProfiles key="pharmacy-items" />],
        ['/pharmacy/settings', PHARMACY_SETTINGS_VIEW, <PharmacySettings key="pharmacy-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // Hotel (docs/modules/hotel.md): the API answers 403 module_inactive without the module.
      ...[
        ['/hotel/front-desk', [HOTEL_CHECKIN, HOTEL_CHECKOUT], <HotelFrontDesk key="hotel-front-desk" />],
        ['/hotel/availability', HOTEL_RESERVATION_VIEW, <HotelAvailability key="hotel-availability" />],
        ['/hotel/reservations', HOTEL_RESERVATION_VIEW, <HotelReservations key="hotel-reservations" />],
        ['/hotel/reservations/new', HOTEL_RESERVATION_CREATE, <HotelNewReservation key="hotel-reservation-new" />],
        ['/hotel/reservations/:reservationId', HOTEL_RESERVATION_VIEW, <HotelReservationDetail key="hotel-reservation" />],
        ['/hotel/folios', HOTEL_FOLIO_VIEW, <HotelFolios key="hotel-folios" />],
        ['/hotel/folios/:folioId', HOTEL_FOLIO_VIEW, <HotelFolioDetail key="hotel-folio" />],
        ['/hotel/guests', HOTEL_GUEST_VIEW, <HotelGuests key="hotel-guests" />],
        ['/hotel/guests/:guestId', HOTEL_GUEST_VIEW, <HotelGuestDetail key="hotel-guest" />],
        ['/hotel/housekeeping', HOTEL_HK_VIEW, <HotelHousekeeping key="hotel-housekeeping" />],
        ['/hotel/night-audit', HOTEL_AUDIT_VIEW, <HotelNightAudit key="hotel-night-audit" />],
        ['/hotel/charge-exceptions', HOTEL_FOLIO_VIEW, <HotelChargeExceptions key="hotel-charge-exceptions" />],
        ['/hotel/rooms', HOTEL_ROOM_VIEW, <HotelRooms key="hotel-rooms" />],
        ['/hotel/rates', HOTEL_RATE_VIEW, <HotelRatePlans key="hotel-rates" />],
        ['/hotel/reports/:kind', HOTEL_REPORT_VIEW, <HotelReports key="hotel-reports" />],
        ['/hotel/settings', HOTEL_SETTINGS_VIEW, <HotelSettings key="hotel-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // Fuel station (docs/modules/fuel-station.md): the API answers 403 module_inactive without the module.
      ...[
        ['/fuel/setup', [FUEL_SETUP_VIEW, ...FUEL_SETTINGS_VIEW], <FuelSetup key="fuel-setup" />],
        ['/fuel/tanks/:tankId', FUEL_SETUP_VIEW, <FuelTankDetail key="fuel-tank" />],
        ['/fuel/prices', FUEL_PRICE_VIEW, <FuelPrices key="fuel-prices" />],
        ['/fuel/shifts', FUEL_SHIFT_VIEW, <FuelShifts key="fuel-shifts" />],
        ['/fuel/shifts/:shiftId', FUEL_SHIFT_VIEW, <FuelShiftDetail key="fuel-shift" />],
        ['/fuel/sales', FUEL_SALE_VIEW, <FuelSales key="fuel-sales" />],
        ['/fuel/settlements', FUEL_VARIANCE_VIEW, <FuelSettlements key="fuel-settlements" />],
        ['/fuel/settlements/:settlementId', FUEL_VARIANCE_VIEW, <FuelSettlementDetail key="fuel-settlement" />],
        ['/fuel/reconciliations', FUEL_VARIANCE_VIEW, <FuelReconciliations key="fuel-reconciliations" />],
        ['/fuel/reconciliations/:reconciliationId', FUEL_VARIANCE_VIEW, <FuelReconciliationDetail key="fuel-reconciliation" />],
        ['/fuel/meter-resets', FUEL_VARIANCE_VIEW, <FuelMeterResets key="fuel-meter-resets" />],
        ['/fuel/dips', FUEL_DIP_VIEW, <FuelDips key="fuel-dips" />],
        ['/fuel/deliveries', FUEL_DELIVERY_VIEW, <FuelDeliveries key="fuel-deliveries" />],
        ['/fuel/deliveries/:deliveryId', FUEL_DELIVERY_VIEW, <FuelDeliveryDetail key="fuel-delivery" />],
        ['/fuel/vehicles', FUEL_VEHICLE_VIEW, <FuelVehicles key="fuel-vehicles" />],
        ['/fuel/vouchers', FUEL_VOUCHER_VIEW, <FuelVouchers key="fuel-vouchers" />],
        ['/fuel/vouchers/:voucherId', FUEL_VOUCHER_VIEW, <FuelVoucherDetail key="fuel-voucher" />],
        ['/fuel/reports/:kind', FUEL_REPORT_VIEW, <FuelReports key="fuel-reports" />],
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
      // Restaurant (docs/modules/restaurant.md): the API answers 403 module_inactive without the module.
      ...[
        ['/restaurant/orders', RESTAURANT_ORDER_VIEW, <RestaurantOrders key="rest-orders" />],
        ['/restaurant/pending-voids', POS_SALE_VOID, <RestaurantPendingVoids key="rest-pending-voids" />],
        ['/restaurant/floor', RESTAURANT_FLOOR_VIEW, <RestaurantFloor key="rest-floor" />],
        ['/restaurant/stations', RESTAURANT_STATION_VIEW, <RestaurantStations key="rest-stations" />],
        ['/restaurant/modifiers', RESTAURANT_MODIFIER_VIEW, <RestaurantModifiers key="rest-modifiers" />],
        ['/restaurant/reports', RESTAURANT_REPORT_VIEW, <RestaurantReports key="rest-reports" />],
        ['/restaurant/settings', RESTAURANT_SETTINGS_EDIT, <RestaurantSettings key="rest-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // Salon (docs/modules/salon.md): the API answers 403 module_inactive without the module.
      ...[
        ['/salon/calendar', SALON_APPOINTMENT_VIEW, <SalonCalendar key="salon-calendar" />],
        ['/salon/services', SALON_SERVICE_VIEW, <SalonServices key="salon-services" />],
        ['/salon/staff', SALON_STAFF_VIEW, <SalonStaff key="salon-staff" />],
        ['/salon/clients', SALON_CLIENT_VIEW, <SalonClients key="salon-clients" />],
        ['/salon/commissions', SALON_COMMISSION_VIEW, <SalonCommissions key="salon-commissions" />],
        ['/salon/reports', SALON_REPORT_VIEW, <SalonReports key="salon-reports" />],
        ['/salon/settings', SALON_SETTINGS_VIEW, <SalonSettings key="salon-settings" />],
      ].map(([path, permission, page]) => ({ path, element: <RequirePermission permission={permission}>{page}</RequirePermission> })),
      // Van sales (docs/modules/van-sales.md): the API answers 403 module_inactive without the module.
      ...[
        ['/van-sales/vans', VAN_SALES_VAN_VIEW, <VanSalesVans key="vs-vans" />],
        ['/van-sales/routes', VAN_SALES_ROUTE_VIEW, <VanSalesRoutes key="vs-routes" />],
        ['/van-sales/routes/:routeId', VAN_SALES_ROUTE_VIEW, <VanSalesRouteDetail key="vs-route" />],
        ['/van-sales/plan', VAN_SALES_ROUTE_VIEW, <VanSalesPlan key="vs-plan" />],
        ['/van-sales/loads', VAN_SALES_LOAD_VIEW, <VanSalesLoads key="vs-loads" />],
        ['/van-sales/runs', VAN_SALES_RUN_VIEW, <VanSalesRuns key="vs-runs" />],
        ['/van-sales/runs/:runId', VAN_SALES_RUN_VIEW, <VanSalesRunDetail key="vs-run" />],
        ['/van-sales/reconciliations', VAN_SALES_RECONCILIATION_VIEW, <VanSalesReconciliations key="vs-reconciliations" />],
        ['/van-sales/reconciliations/:reconciliationId', VAN_SALES_RECONCILIATION_VIEW, <VanSalesReconciliationDetail key="vs-reconciliation" />],
        ['/van-sales/accounts', VAN_SALES_CREDIT_VIEW, <VanSalesAccounts key="vs-accounts" />],
        ['/van-sales/accounts/:customerId', VAN_SALES_CREDIT_VIEW, <VanSalesAccountDetail key="vs-account" />],
        ['/van-sales/credit-reviews', VAN_SALES_CREDIT_REVIEW, <VanSalesCreditReviews key="vs-credit-reviews" />],
        ['/van-sales/collections', VAN_SALES_COLLECTION_VIEW, <VanSalesCollections key="vs-collections" />],
        ['/van-sales/reports', VAN_SALES_REPORT_VIEW, <VanSalesReports key="vs-reports" />],
        ['/van-sales/settings', VAN_SALES_SETTINGS_EDIT, <VanSalesSettings key="vs-settings" />],
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
      // AUTH-11: single sign-on (the Owner's, core.sso.manage).
      {
        path: '/settings/sso',
        element: (
          <RequirePermission permission="core.sso.manage" tenantWide>
            <SingleSignOn />
          </RequirePermission>
        ),
      },
      // RBAC-13b: support requests, active sessions and their history (Owner, Admin).
      {
        path: '/settings/security/support-access',
        element: (
          <RequirePermission permission="core.support_access.view" tenantWide>
            <SupportAccess />
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
      // MKT-01, MKT-02: the marketplace, installed apps and the developer portal's apps.
      { path: '/marketplace', element: <RequirePermission permission="core.marketplace.view" tenantWide><Marketplace /></RequirePermission> },
      { path: '/marketplace/:appId', element: <RequirePermission permission="core.marketplace.view" tenantWide><MarketplaceApp /></RequirePermission> },
      { path: '/settings/apps', element: <RequirePermission permission="core.marketplace.view" tenantWide><InstalledApps /></RequirePermission> },
      { path: '/settings/apps/:installationId', element: <RequirePermission permission="core.marketplace.view" tenantWide><InstalledApp /></RequirePermission> },
      { path: '/settings/developer-apps', element: <RequirePermission permission="core.developer.manage" tenantWide><DeveloperApps /></RequirePermission> },
      { path: '/settings/developer-apps/:appId', element: <RequirePermission permission="core.developer.manage" tenantWide><DeveloperApp /></RequirePermission> },
      // EXP-02: the full data export (Owners only; the API also checks the Owner role).
      // SUB-02..SUB-06: the plan, invoices and payment methods.
      { path: '/settings/subscription', element: <RequirePermission permission="core.subscription.view" tenantWide><Subscription /></RequirePermission> },
      // LIM-01, LIM-02: usage against the plan (Owners only).
      { path: '/settings/usage', element: <RequirePermission owner><Usage /></RequirePermission> },
      { path: '/settings/data-export', element: <RequirePermission permission="core.tenant.export" tenantWide><DataExport /></RequirePermission> },
      // HOOK-01: webhooks, their delivery log and the developer guide (Owner, Admin).
      { path: '/settings/webhooks', element: <RequirePermission permission="core.webhook.manage" tenantWide><Webhooks /></RequirePermission> },
      { path: '/settings/webhooks/:webhookId', element: <RequirePermission permission="core.webhook.manage" tenantWide><WebhookDetail /></RequirePermission> },
      // NFR-08: data subject requests and marketing consent (Owner, Admin).
      { path: '/settings/privacy', element: <RequirePermission permission={['core.privacy_request.view', 'core.consent.view']} tenantWide><Privacy /></RequirePermission> },
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
      { path: '/dashboard/customise', handle: { wide: true }, element: <NotInReadOnlySession><DashboardDesigner key="mine" personal /></NotInReadOnlySession> },
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
