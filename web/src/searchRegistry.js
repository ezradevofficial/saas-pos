import { AUTOMATION_VIEW, HR_LEAVE_VIEW, HR_ROSTER_VIEW } from '@/layouts/navigation'
import { RESERVATION_CREATE as HOTEL_RESERVATION_CREATE } from '@/pages/hotel/hotelData'
import { LOT_RECEIVE, WRITEOFF_CREATE } from '@/pages/pharmacy/pharmacyData'
import { IMPORT_PERMISSION } from '@/pages/settings/imports/importData'

/**
 * Quick search registry (LAY-01, RBAC-08): the pages and "new" actions the
 * palette offers besides the sidebar's own items. Entries have the same shape
 * as navigation items (`to`, `permission`, `module`, `tenantWide`,
 * `needsCompany`), so they are filtered by the same `visibleGroups` as the
 * sidebar. Each `to` and `permission` must match its route in routes.jsx;
 * searchRegistry.test.jsx fails when they drift apart.
 * Labels are functions of `t` with literal keys, so the i18n check sees them.
 */

/** Pages with a route but no sidebar item. `group` is a nav group id. */
export const EXTRA_PAGES = [
  { to: '/dashboard/customise', group: 'overview', label: (t) => t('quickSearch.pages.customiseDashboard'), module: 'core', keywords: 'personalise personnaliser tableau de bord widgets' },
  { to: '/notifications', group: 'workspace', label: (t) => t('quickSearch.pages.notifications'), module: 'core', keywords: 'inbox alerts boite de reception alertes' },
  { to: '/settings/automation-runs', group: 'automation', label: (t) => t('quickSearch.pages.automationRuns'), permission: AUTOMATION_VIEW, module: 'core', keywords: 'log history journal historique executions' },
  { to: '/accounting/reports/ledger', group: 'accounting', label: (t) => t('quickSearch.pages.generalLedger'), permission: 'accounting.report.view', module: 'accounting', keywords: 'grand livre ledger' },
  { to: '/hr/leave/requests', group: 'people', label: (t) => t('quickSearch.pages.leaveRequests'), permission: HR_LEAVE_VIEW, module: 'hr', keywords: 'holiday vacation conge demandes' },
  { to: '/hr/leave/balances', group: 'people', label: (t) => t('quickSearch.pages.leaveBalances'), permission: HR_LEAVE_VIEW, module: 'hr', keywords: 'holiday vacation conge soldes' },
  { to: '/hr/leave/calendar', group: 'people', label: (t) => t('quickSearch.pages.leaveCalendar'), permission: HR_LEAVE_VIEW, module: 'hr', keywords: 'holiday vacation conge calendrier' },
  { to: '/hr/leave/types', group: 'people', label: (t) => t('quickSearch.pages.leaveTypes'), permission: HR_LEAVE_VIEW, module: 'hr', keywords: 'holiday vacation conge types' },
  { to: '/hr/rosters/week', group: 'people', label: (t) => t('quickSearch.pages.rosterWeek'), permission: HR_ROSTER_VIEW, module: 'hr', keywords: 'schedule shifts planning horaire semaine' },
  { to: '/hr/rosters/swaps', group: 'people', label: (t) => t('quickSearch.pages.rosterSwaps'), permission: HR_ROSTER_VIEW, module: 'hr', keywords: 'shift swap echanges quarts' },
  { to: '/hr/attendance/timesheets', group: 'people', label: (t) => t('quickSearch.pages.timesheets'), permission: 'hr.attendance.view', module: 'hr', keywords: 'hours time heures feuilles de temps' },
  { to: '/hr/attendance/punches', group: 'people', label: (t) => t('quickSearch.pages.punches'), permission: 'hr.attendance.view', module: 'hr', keywords: 'clock in out pointages presence' },
  { to: '/hr/attendance/devices', group: 'people', label: (t) => t('quickSearch.pages.attendanceDevices'), permission: 'hr.device.manage', module: 'hr', keywords: 'clock terminals appareils pointeuse' },
  { to: '/pharmacy/receive', group: 'pharmacy', label: (t) => t('quickSearch.pages.pharmacyReceive'), permission: LOT_RECEIVE, module: 'pharmacy', keywords: 'receive goods reception stock lots' },
]

/** "New ..." actions: each opens an existing create page. `group` is a nav group id. */
export const ACTIONS = [
  { to: '/catalogue/items/new', group: 'catalogue', label: (t) => t('quickSearch.actions.newItem'), permission: 'core.item.create', module: 'core', keywords: 'add item product produit article creer ajouter' },
  { to: '/contacts/customers/new', group: 'contacts', label: (t) => t('quickSearch.actions.newCustomer'), permission: 'core.party.create', module: 'core', keywords: 'add client customer contact ajouter' },
  { to: '/contacts/suppliers/new', group: 'contacts', label: (t) => t('quickSearch.actions.newSupplier'), permission: 'core.party.create', module: 'core', keywords: 'add vendor fournisseur ajouter' },
  { to: '/settings/users/invite', group: 'settings', label: (t) => t('quickSearch.actions.inviteUser'), permission: 'core.user.invite', module: 'core', keywords: 'add user member staff utilisateur inviter ajouter' },
  { to: '/settings/roles/new', group: 'settings', label: (t) => t('quickSearch.actions.newRole'), permission: 'core.role.create', tenantWide: true, module: 'core', keywords: 'permissions role creer' },
  { to: '/settings/imports/new', group: 'settings', label: (t) => t('quickSearch.actions.newImport'), permission: IMPORT_PERMISSION, module: 'core', keywords: 'upload csv excel importer fichier' },
  { to: '/procurement/orders/new', group: 'procurement', label: (t) => t('quickSearch.actions.newPurchaseOrder'), permission: 'procurement.po.create', module: 'procurement', keywords: 'po buy order achat bon de commande' },
  { to: '/procurement/requisitions/new', group: 'procurement', label: (t) => t('quickSearch.actions.newPurchaseRequisition'), permission: 'procurement.requisition.create', module: 'procurement', keywords: 'request demande achat' },
  { to: '/procurement/receipts/new', group: 'procurement', label: (t) => t('quickSearch.actions.newGoodsReceipt'), permission: 'procurement.receipt.create', module: 'procurement', keywords: 'receive grn reception livraison' },
  { to: '/inventory/transfers/new', group: 'inventory', label: (t) => t('quickSearch.actions.newTransfer'), permission: 'inventory.transfer.create', module: 'inventory', keywords: 'move stock transfert mouvement' },
  { to: '/inventory/adjustments/new', group: 'inventory', label: (t) => t('quickSearch.actions.newAdjustment'), permission: 'inventory.adjustment.create', module: 'inventory', keywords: 'correct stock write off ajustement correction' },
  { to: '/inventory/counts/new', group: 'inventory', label: (t) => t('quickSearch.actions.newCount'), permission: 'inventory.count.create', module: 'inventory', keywords: 'stocktake inventory count inventaire comptage' },
  { to: '/accounting/journals/new', group: 'accounting', label: (t) => t('quickSearch.actions.newJournal'), permission: 'accounting.journal.create', module: 'accounting', keywords: 'entry ledger ecriture journal comptable' },
  { to: '/accounting/documents/new', group: 'accounting', label: (t) => t('quickSearch.actions.newInvoice'), permission: 'accounting.invoice.create', module: 'accounting', keywords: 'bill invoice facture' },
  { to: '/hr/employees/new', group: 'people', label: (t) => t('quickSearch.actions.newEmployee'), permission: 'hr.employee.create', module: 'hr', keywords: 'hire staff employe embaucher ajouter' },
  { to: '/hotel/reservations/new', group: 'hotel', label: (t) => t('quickSearch.actions.newReservation'), permission: HOTEL_RESERVATION_CREATE, module: 'hotel', keywords: 'book room booking reservation chambre reserver' },
  { to: '/crm/quotes/new', group: 'crm', label: (t) => t('quickSearch.actions.newQuote'), permission: 'crm.quote.create', module: 'crm', keywords: 'quotation estimate devis offre' },
  { to: '/crm/leads/new', group: 'crm', label: (t) => t('quickSearch.actions.newLead'), permission: 'crm.lead.create', module: 'crm', keywords: 'prospect piste' },
  { to: '/crm/opportunities/new', group: 'crm', label: (t) => t('quickSearch.actions.newOpportunity'), permission: 'crm.opportunity.create', module: 'crm', keywords: 'deal pipeline affaire opportunite' },
  { to: '/fixed-assets/assets/new', group: 'fixedAssets', label: (t) => t('quickSearch.actions.newAsset'), permission: 'fixed_assets.asset.manage', module: 'fixed_assets', keywords: 'equipment immobilisation equipement' },
  { to: '/manufacturing/orders/new', group: 'manufacturing', label: (t) => t('quickSearch.actions.newProductionOrder'), permission: 'manufacturing.order.create', module: 'manufacturing', keywords: 'make production fabrication ordre' },
  { to: '/stores/requisitions/new', group: 'stores', label: (t) => t('quickSearch.actions.newStoreRequisition'), permission: 'stores.requisition.create', module: 'stores', keywords: 'request issue demande sortie magasin' },
  { to: '/loyalty/promotions/new', group: 'loyalty', label: (t) => t('quickSearch.actions.newPromotion'), permission: 'loyalty.promotion.create', module: 'loyalty', keywords: 'discount offer remise promo' },
  { to: '/pharmacy/write-offs/new', group: 'pharmacy', label: (t) => t('quickSearch.actions.newWriteOff'), permission: WRITEOFF_CREATE, module: 'pharmacy', keywords: 'expired destroy perte peremption' },
]

/** English and French synonyms for sidebar pages, by route. Matching ignores accents. */
export const PAGE_KEYWORDS = {
  '/': 'home overview accueil',
  '/inventory/stock': 'stock inventory quantity levels stock disponible inventaire quantites',
  '/inventory/movements': 'stock ledger history mouvements historique',
  '/inventory/counts': 'stocktake inventory count comptage inventaire',
  '/inventory/valuation': 'cost value valorisation',
  '/payroll/runs': 'salary salaries wages pay salaire paie',
  '/payroll/payslips': 'salary slip bulletin de paie salaire',
  '/hr/employees': 'staff people personnel employes',
  '/settings/users': 'team members staff utilisateurs equipe',
  '/settings/roles': 'permissions access acces droits',
  '/settings/organisation': 'company branch location entreprise succursale',
  '/settings/security': 'password 2fa securite mot de passe',
  '/settings/subscription': 'billing plan pay facturation abonnement paiement',
  '/settings/currencies': 'money devises monnaie',
  '/settings/taxes': 'vat tax tva impots taxes',
  '/settings/exchange-rates': 'fx rates taux de change',
  '/settings/appearance': 'theme dark mode language theme sombre langue',
  '/catalogue/items': 'products articles produits',
  '/contacts/customers': 'clients customers',
  '/contacts/suppliers': 'vendors fournisseurs',
  '/pos/sales': 'receipts sell till ventes recus caisse',
  '/pos/shifts': 'cash up till shifts caisse sessions',
  '/accounting/reports': 'balance sheet profit loss bilan resultat rapports',
  '/accounting/journals': 'journal entries ecritures',
  '/approvals': 'approve pending inbox approbation en attente',
  '/help': 'manual guide docs aide manuel documentation',
}
