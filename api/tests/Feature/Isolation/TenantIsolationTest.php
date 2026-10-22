<?php

namespace Tests\Feature\Isolation;

use App\Core\DataExport\ExportColumns;
use App\Core\DataExport\Models\TenantExport;
use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\Exports\ListExport;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\MasterData\History\HistoryTypes;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\RegistersTillModule;
use Tests\Support\Configuration\TestLayoutKind;
use Tests\Support\GlobalTables;
use Tests\Support\TenantFixture;
use Tests\Support\TwoTenants;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;
use ZipArchive;

/**
 * TEN-01, the sprint exit criterion: a user of tenant A provably cannot see
 * or change anything of tenant B through the API, its lists, its exports or
 * the database itself. Tables and routes are discovered at run time, so a
 * new table or route is covered (or fails loudly) without editing this file.
 *
 * Run alone with `php artisan test --testsuite=Isolation`.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshTenantDatabase, RegistersTillModule;

    /**
     * Routes that take no bearer token, with why they cannot leak another
     * tenant's data. Keyed "METHOD uri".
     */
    public const PUBLIC_ROUTES = [
        'POST api/v1/auth/sign-up' => 'creates a new tenant; reads nothing of an existing one',
        // MKT-02, API-02 (ADR 024): OAuth for marketplace apps (OAuthInstallTest).
        'POST api/v1/oauth/token' => 'API-02: the OAuth token endpoint; the client authenticates (its secret and the PKCE verifier) and the code or refresh token, stored only as sha256 of its identifier, names its tenant through a security-definer function that returns only the id; rate-limited per IP',
        'GET api/v1/marketplace/logos/{version}' => 'MKT-01: the logo of an approved app version, global marketplace data the listing shows every tenant; no tenant row is read',
        'POST api/v1/auth/verify' => 'needs the challenge id and its one-time code',
        'POST api/v1/auth/verify/resend' => 'needs the challenge id; answers only a masked destination',
        'POST api/v1/auth/sign-in' => 'needs the login and password',
        'POST api/v1/auth/two-factor/challenge' => 'needs the challenge id and its one-time code',
        'POST api/v1/auth/password/forgot' => 'answers the same whether or not the login exists',
        'POST api/v1/auth/password/reset' => 'needs the one-time reset code',
        'GET api/v1/auth/invitations/{token}' => 'the 40-character invitation token is the credential',
        'POST api/v1/auth/invitations/{token}/accept' => 'the 40-character invitation token is the credential',
        'POST api/v1/devices/pair' => 'the one-time pairing code is the credential',
        'GET api/v1/developers/openapi.json' => 'API-04: the committed OpenAPI document of the public routes, the same for everyone; no tenant data; rate-limited per IP',
        'GET api/v1/media/{path}' => 'temporary signed URL for one file and one user; the controller enters the tenant the path names and checks that user may view the item (MD-02)',
        'GET api/v1/custom-field-files/{path}' => 'temporary signed URL for one file and one user; the controller enters the tenant the path names and checks that user may still see the record and the field (CF-01)',
        'GET api/v1/custom-form-files/{path}' => 'temporary signed URL for one file and one user; the controller enters the tenant the path names and checks that user may still see the record (CF-04)',
        'GET api/v1/import-files/{path}' => 'temporary signed URL for one import file and one user; the controller enters the tenant the path names, finds the import there (RLS) and checks that user may still see it (IMP-03)',
        'GET api/v1/hr-files/{path}' => 'temporary signed URL for one HR document and one user; the controller enters the tenant the path names, finds the document there (RLS) and checks that user may still see its category for that employee (HR-27)',
        'GET api/v1/approval-files/{path}' => 'temporary signed URL for one file and one user; the controller enters the tenant the path names and checks that user may still see the approval (APR-03)',
        'GET api/v1/approvals/email/{token}' => 'the 48-character single-use approval token is the credential; answers only what confirming would do (APR-08)',
        'POST api/v1/approvals/email/{token}' => 'the 48-character single-use approval token is the credential (APR-08)',
        'GET api/v1/public/branding' => 'BR-04: the sign-in page\'s branding of a host, through a security-definer function that returns only public fields (name, look, welcome text, signed asset URLs), never ids or rows; rate-limited',
        // AUTH-11, ADR 023: single sign-on before anyone is signed in (SsoAuthController); rate-limited per address.
        'POST api/v1/auth/sso/start' => 'AUTH-11: answers only an IdP sign-in URL or the names of the enabled connections of the tenant whose verified email domain or host was named (security-definer lookups returning tenant ids only); never a row of a tenant',
        'GET api/v1/auth/sso/oidc/callback' => 'AUTH-11: the single-use state (only its sha256 is a cache key) names the flow and its tenant; answers only a redirect with a ticket or an error code in the fragment',
        'POST api/v1/auth/sso/saml/{connection}/acs' => 'AUTH-11: the single-use RelayState names the flow and its tenant; the connection in the path must be the flow\'s; answers only a redirect with a ticket or an error code',
        'POST api/v1/auth/sso/complete' => 'AUTH-11: needs the single-use ticket and the browser binding secret of its flow; answers the signed-in user\'s own token, as a password sign-in does',
        'GET api/v1/tls/ask' => 'BR-05: answers only 200 or 404 for whether a host is a verified custom domain (Caddy on-demand TLS); "no" cached 60 s; reachable only from Caddy on the private interface',
        'GET api/v1/branding/assets/{path}' => 'BR-02: a signed URL for one public brand asset; the controller enters the tenant the path names and serves the file only if that tenant has it',
        'GET api/v1/tenant-exports/{export}/download' => 'EXP-02: temporary signed URL for one full export and its requesting Owner; the controller enters the signed tenant and serves the zip only to that user while they are an active Owner',
        'POST api/v1/payments/callbacks/{token}/{kind}' => 'a payment provider\'s callback: the 48-character callback token names one payment method (its tenant found by a security-definer function), the caller must be the provider\'s address, and it answers only "Accepted"',
        'GET api/v1/catalogue' => 'ONB-03: the global plan catalogue of a country (no tenant data, global tables); rate-limited',
        'POST api/v1/catalogue/quote' => 'ONB-03: prices a selection against the global catalogue; reads no tenant row; rate-limited',
        'POST api/v1/auth/support-session' => 'RBAC-13c: a support session\'s one-time entry code (the tenant id and a 48-character secret, stored as sha256, valid 60 seconds, used once) is the credential; the tenant it names is entered and the code\'s hash looked up under its RLS; rate-limited (SupportAccessTest)',
        'POST api/v1/platform/auth/sign-in' => 'ADR 013: platform staff sign-in (platform_users, global); needs the email and password; rate-limited',
        'POST api/v1/platform/auth/two-factor' => 'ADR 013: needs the encrypted sign-in challenge and a TOTP code',
        'GET api/v1/platform/auth/setup/{token}' => 'ADR 013: the 48-character one-time setup token is the credential; answers only the staff member\'s name and email',
        'POST api/v1/platform/auth/setup/{token}' => 'ADR 013: the 48-character one-time setup token is the credential',
        // Procurement (docs/modules/procurement.md): the supplier portal, tested by modules/Procurement/tests/PortalTest.php.
        'POST api/v1/supplier-portal/sessions' => 'PRC-07: the 48-character single-bidder link token (stored as sha256, valid until the tender deadline) is the credential; its tenant is found by a security-definer function that returns only the id; rate-limited',
        'GET api/v1/supplier-portal/notices/{notice}' => 'PRC-08: an open tender\'s public notice, found by the hash of its 48-character token through a security-definer function; answers only the tender\'s public terms (never bidders); rate-limited',
        'POST api/v1/supplier-portal/notices/{notice}/register' => 'PRC-08: registers an outsider on that open tender only; the link goes to the email typed; rate-limited per IP and per email',
        'DELETE api/v1/supplier-portal/sessions/current' => 'PRC-07: the supplier portal (PRC-07): no tenant user token; the portal session bearer token (`pts_` + 48 characters, stored as sha256) is the credential, its tenant found by a security-definer function that returns only the id, and every query bound to that session\'s tender and bidder under that tenant\'s RLS; rate-limited (ProcurementPortalTest)',
        'GET api/v1/supplier-portal/tender' => 'PRC-07: the supplier portal (PRC-07): no tenant user token; the portal session bearer token (`pts_` + 48 characters, stored as sha256) is the credential, its tenant found by a security-definer function that returns only the id, and every query bound to that session\'s tender and bidder under that tenant\'s RLS; rate-limited (ProcurementPortalTest)',
        'PUT api/v1/supplier-portal/bid' => 'PRC-09: the supplier portal (PRC-07): no tenant user token; the portal session bearer token (`pts_` + 48 characters, stored as sha256) is the credential, its tenant found by a security-definer function that returns only the id, and every query bound to that session\'s tender and bidder under that tenant\'s RLS; rate-limited (ProcurementPortalTest)',
        'POST api/v1/supplier-portal/bid/files' => 'PRC-09: the supplier portal (PRC-07): no tenant user token; the portal session bearer token (`pts_` + 48 characters, stored as sha256) is the credential, its tenant found by a security-definer function that returns only the id, and every query bound to that session\'s tender and bidder under that tenant\'s RLS; rate-limited (ProcurementPortalTest)',
        'POST api/v1/supplier-portal/bid/withdraw' => 'PRC-09: the supplier portal (PRC-07): no tenant user token; the portal session bearer token (`pts_` + 48 characters, stored as sha256) is the credential, its tenant found by a security-definer function that returns only the id, and every query bound to that session\'s tender and bidder under that tenant\'s RLS; rate-limited (ProcurementPortalTest)',
        // E-commerce (docs/modules/ecommerce.md): the storefront, its images and the WhatsApp webhook.
        'GET api/v1/shop/{store}' => 'ECOM-05: the public face of the store; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'GET api/v1/shop/{store}/products' => 'ECOM-02: published products; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'GET api/v1/shop/{store}/products/{slug}' => 'ECOM-02: one published product; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'POST api/v1/shop/{store}/quote' => 'ECOM-09: a cart priced by the server; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'POST api/v1/shop/{store}/orders' => 'ECOM-11 to ECOM-13: placing an order (Idempotency-Key); the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'GET api/v1/shop/{store}/orders/{number}' => 'ECOM-21: tracking needs the 40-character tracking token (sha256) or the session of its customer; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'POST api/v1/shop/{store}/orders/{number}/pay' => 'ECOM-15: a payment retry, with the tracking token; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'POST api/v1/shop/{store}/orders/{number}/check' => 'ECOM-15: the payment check, with the tracking token; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'POST api/v1/shop/{store}/auth/code' => 'ECOM-08: a sign-in code to a contact (core OTP rules); the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'POST api/v1/shop/{store}/auth/verify' => 'ECOM-08: a sign-in code exchanged for a customer session; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'POST api/v1/shop/{store}/auth/sign-out' => 'ECOM-08: ends the customer session; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'GET api/v1/shop/{store}/account' => 'ECOM-08: the own orders of the signed-in customer; the storefront API (ECOM-05 to ECOM-08): no tenant token; the store is found by its host and handle through a security-definer function that returns only the tenant id of a live store (ADR 002), every query under that tenant RLS; a bearer token must be a customer session of that store (`ecs_`, sha256), a tenant user token is refused; rate-limited (StorefrontTest)',
        'POST api/v1/shop/whatsapp/{token}' => 'ECOM-24: the inbound WhatsApp webhook; the 40-character token (stored as sha256) is the credential, its tenant found by a security-definer function that returns only the id; the provider driver verifies the request; rate-limited per token (WhatsAppAndCustomersTest)',
        'GET api/v1/shop-media/{image}' => 'ECOM-02: an item image of a published product behind a temporary signed URL (the signature is the credential); the signed tenant is entered (ShopImageController)',
        // CRM (docs/modules/crm.md): the public lead form, tested by modules/CRM/tests/LeadsTest.php.
        'POST api/v1/crm/forms/{token}' => 'CRM-06: a public web form posting a lead; the 40-character form token (stored as sha256) is the credential, its tenant found by a security-definer function that returns only the id; answers only {received: true} (or 404); rate-limited per IP and per form',
        'DELETE api/v1/supplier-portal/bid/files/{file}' => 'PRC-09: removes one of the session bidder\'s own unsubmitted uploads; the supplier portal (PRC-07): no tenant user token; the portal session bearer token (`pts_` + 48 characters, stored as sha256) is the credential, its tenant found by a security-definer function that returns only the id, and every query bound to that session\'s tender and bidder under that tenant\'s RLS; rate-limited (ProcurementPortalTest)',
    ];

    /**
     * Route parameter name => which of B's ids to put there. A route with a
     * parameter missing here fails the suite: add the parameter (and, in
     * TwoTenants, a row of that type in both tenants).
     */
    public const PARAMETERS = [
        // RBAC-13: a support access request (pending) of each tenant.
        'support_access_grant' => 'support_access_grant',
        'company' => 'company',
        'branch' => 'branch',
        'location' => 'location',
        'device' => 'device',
        'user' => 'user',
        'role' => 'role',
        'invitation' => 'invitation',
        'assignment' => 'assignment',
        'tenant_currency' => 'tenant_currency',
        'tax_code' => 'tax_code',
        'tax_category' => 'tax_category',
        'price_list' => 'price_list',
        'party' => 'party',
        'item' => 'item',
        'item_category' => 'item_category',
        'uom' => 'uom',
        'item_image' => 'item_image',
        'item_price' => 'item_price', // MD-03 follow-up: item-prices/{item_price}/archive|restore
        'api_key' => 'api_key', // API-02: api-keys/{api_key}
        'developer_app' => 'developer_app', // MKT-01: developer/apps/{developer_app}, the tenant's own draft app (global table, RLS on the developer tenant)
        'app_installation' => 'app_installation', // MKT-02: apps/installed/{app_installation}, the shared app installed in each tenant
        'payment_method' => 'payment_method',
        'department' => 'department',
        'cost_centre' => 'cost_centre',
        'project' => 'project',
        'workflow' => 'workflow',
        'workflow_version' => 'workflow_version',
        'document' => 'document', // WF-10: document-workflows/{document_type}/{document}, the test type's document
        'notification' => 'notification', // NOT-01: POST notifications/{notification}/read|archive
        'automation_rule' => 'automation_rule', // AUTO-01..AUTO-04: automation-rules/{automation_rule}[/enable|disable|archive|test]
        'automation_run' => 'automation_run', // AUTO-05: automation-runs/{automation_run}
        'approval' => 'approval', // APR-04: approvals/{approval}, a request waiting for the manager
        'credit_limit_change' => 'credit_limit_change', // WF-01: credit-limit-changes/{credit_limit_change}, a pending request
        'delegation' => 'delegation', // APR-06: me/delegations/{delegation}/revoke, the manager's delegation (A's owner gets 404 on B's)
        'payment_intent' => 'payment_intent', // payments/intents/{payment_intent} (device), a manual payment at the till's location
        'payment_receipt' => 'payment_receipt', // payment-receipts/{payment_receipt}/match, money received that matched nothing
        'fiscal_submission' => 'fiscal_submission', // fiscal-submissions/{fiscal_submission}[/retry], an accepted sale
        'custom_form_type' => 'custom_form_type', // CF-04: custom-form-types/{custom_form_type}[/archive|restore|records|attachments]
        'custom_form_record' => 'custom_form_record', // CF-04: custom-form-records/{custom_form_record}[/submit|cancel|archive|restore], a draft
        'custom_field' => 'custom_field', // CF-01: custom-fields/{custom_field}[/archive|restore], the item's colour field
        'pos_sale' => 'pos_sale', // POS-12: pos/sales/{pos_sale}, a partly refunded sale
        'pos_shift' => 'pos_shift', // POS-12: pos/shifts/{pos_shift}, the till's open shift
        'pos_void' => 'pos_void', // H2: pos/voids/{pos_void}/approve|reject, a held void
        'pos_refund' => 'pos_refund', // H2: pos/refunds/{pos_refund}/approve|reject, a held refund
        'pos_cash_movement' => 'pos_cash_movement', // H2: pos/cash-movements/{pos_cash_movement}/approve|reject
        'tenant_export' => 'tenant_export', // EXP-02: tenant-exports/{tenant_export}/link, the Owner's export request
        'webhook' => 'webhook', // HOOK-01: webhooks/{webhook}[/rotate-secret|test|archive]
        'privacy_request' => 'privacy_request', // NFR-08: privacy/requests/{privacy_request}[/approve|reject|execute|report]
        'webhook_delivery' => 'webhook_delivery', // HOOK-01: webhook-deliveries/{webhook_delivery}[/replay]
        'tenant_domain' => 'tenant_domain', // BR-05: branding/domains/{tenant_domain}/check|archive
        'sso_connection' => 'sso_connection', // AUTH-11: sso/connections/{sso_connection}[/enable|disable|archive|test]
        'sso_domain' => 'sso_domain', // AUTH-11: sso/domains/{sso_domain}/check|archive
        'sso_identity' => 'sso_identity', // AUTH-11: me/sso/identities/{sso_identity}/archive, users/{user}/sso-identities/{sso_identity}/archive
        'brand_asset' => 'brand_asset', // BR-02, LAY-05: sync/brand-assets/{brand_asset} (device), the tenant's logo
        'billing_invoice' => 'billing_invoice', // SUB-03: subscription/invoices/{billing_invoice}[/pdf|pay], the first invoice (the tenant's copy)
        'billing_payment' => 'billing_payment', // SUB-04: subscription/payments/{billing_payment}, an M-Pesa payment of that invoice
        'billing_payment_method' => 'billing_payment_method', // SUB-04: subscription/payment-methods/{billing_payment_method}/default|archive, a saved wallet
        'config_document' => 'config_document', // LAY-06: config/{kind}/{config_document}[/draft|publish|rollback|copy|discard-draft]
        'preview' => 'template_preview', // TPL-01: templates/previews/{preview}/pdf, the owner's preview (a cache key bound to its tenant and user)
        'document_share' => 'document_share', // TPL-04: pos/sales/{pos_sale}/shares/{document_share}/revoke, a link to the sale's receipt
        // Accounting (docs/modules/accounting.md): an account, rule, exception, draft journal, recurring journal, draft invoice,
        // open credit note, receipt, bank account, unmatched statement line, bank rule, budget and period of each tenant.
        'acc_account' => 'acc_account',
        'acc_posting_rule' => 'acc_posting_rule',
        'acc_posting_exception' => 'acc_posting_exception',
        'acc_journal' => 'acc_journal',
        'acc_recurring_journal' => 'acc_recurring_journal',
        'acc_document' => 'acc_document',
        'acc_credit_note' => 'acc_credit_note',
        'acc_payment' => 'acc_payment',
        'acc_bank_account' => 'acc_bank_account',
        'acc_bank_statement_line' => 'acc_bank_statement_line',
        'acc_bank_rule' => 'acc_bank_rule',
        'acc_budget' => 'acc_budget',
        'acc_period' => 'acc_period',
        'record' => 'party', // GET history/{type}/{record}, with type = party
        'import' => 'import', // IMP-01..IMP-03: imports/{import}[/preview|dry-run|run|undo], a completed customers import
        // Loyalty (docs/modules/loyalty.md): a promotion, its coupon, a credit account, its statement, a gift card.
        'loyalty_promotion' => 'loyalty_promotion',
        'loyalty_coupon' => 'loyalty_coupon',
        'loyalty_account' => 'loyalty_account',
        'loyalty_statement' => 'loyalty_statement',
        'loyalty_gift_card' => 'loyalty_gift_card',
        // Inventory (docs/modules/inventory.md): a pending transfer and adjustment, a running count.
        'inventory_transfer' => 'inventory_transfer',
        'inventory_adjustment' => 'inventory_adjustment',
        'inventory_count' => 'inventory_count',
        // Procurement (docs/modules/procurement.md): drafts of each document, a bidder, an opened bid and its file,
        // an awarded tender's bid (its letter), a draft GRN, a matched invoice and a draft landed cost.
        'procurement_requisition' => 'procurement_requisition',
        'procurement_rfq' => 'procurement_rfq',
        'procurement_tender' => 'procurement_tender',
        'procurement_bidder' => 'procurement_bidder',
        'procurement_bid' => 'procurement_bid',
        'procurement_bid_file' => 'procurement_bid_file',
        'procurement_decided_bid' => 'procurement_decided_bid',
        'procurement_order' => 'procurement_order',
        'procurement_receipt' => 'procurement_receipt',
        'procurement_invoice' => 'procurement_invoice',
        'procurement_landed_cost' => 'procurement_landed_cost',
        // Stores (docs/modules/stores.md): a partly issued requisition, its issue (partly returned), the return.
        'stores_requisition' => 'stores_requisition',
        'stores_issue' => 'stores_issue',
        'stores_return' => 'stores_return',
        // Manufacturing (docs/modules/manufacturing.md): a BOM (its draft version), a running order.
        'mfg_bom' => 'mfg_bom',
        'mfg_bom_version' => 'mfg_bom_version',
        'mfg_order' => 'mfg_order',
        // HR (docs/modules/hr.md): an employee linked to the Owner and what hangs off them.
        'hr_employee' => 'hr_employee',
        'hr_pay_account' => 'hr_pay_account',
        'hr_contract' => 'hr_contract',
        'hr_document' => 'hr_document',
        'hr_position' => 'hr_position',
        'hr_leave_type' => 'hr_leave_type',
        'hr_leave_request' => 'hr_leave_request',
        'hr_shift' => 'hr_shift',
        'hr_rotation' => 'hr_rotation',
        'hr_roster' => 'hr_roster',
        'hr_roster_shift' => 'hr_roster_shift',
        'hr_shift_swap' => 'hr_shift_swap',
        'hr_time_rule' => 'hr_time_rule',
        'hr_punch' => 'hr_punch',
        'hr_attendance_period' => 'hr_attendance_period',
        'hr_attendance_device' => 'hr_attendance_device',
        'hr_checklist_template' => 'hr_checklist_template',
        'hr_checklist_item' => 'hr_checklist_item',
        'hr_disciplinary_case' => 'hr_disciplinary_case',
        'hr_appraisal_cycle' => 'hr_appraisal_cycle',
        'hr_appraisal' => 'hr_appraisal',
        // Fixed assets (docs/modules/fixed-assets.md).
        'fa_asset' => 'fa_asset',
        'fa_category' => 'fa_category',
        'fa_item_category' => 'fa_item_category',
        'fa_document' => 'fa_document',
        'fa_run' => 'fa_run',
        'fa_transfer' => 'fa_transfer',
        'fa_disposal' => 'fa_disposal',
        'fa_schedule' => 'fa_schedule',
        'fa_work_order' => 'fa_work_order',
        // CRM (docs/modules/crm.md): the default pipeline and a stage, a source, a team, a rule, a lead form,
        // an open lead and opportunity, a draft quote, an activity and a target.
        'crm_pipeline' => 'crm_pipeline',
        'crm_stage' => 'crm_stage',
        'crm_option' => 'crm_source',
        'crm_team' => 'crm_team',
        'crm_assignment_rule' => 'crm_assignment_rule',
        'crm_lead_form' => 'crm_lead_form',
        'crm_lead' => 'crm_lead',
        'crm_opportunity' => 'crm_opportunity',
        'crm_quote' => 'crm_quote',
        'crm_activity' => 'crm_activity',
        'crm_target' => 'crm_target',
        // E-commerce (docs/modules/ecommerce.md): a store, its product and zone, an accepted order and its assigned delivery, a rider, a WhatsApp chat.
        'ecom_store' => 'ecom_store',
        'ecom_product' => 'ecom_product',
        'ecom_zone' => 'ecom_zone',
        'ecom_order' => 'ecom_order',
        'ecom_delivery' => 'ecom_delivery',
        'ecom_rider' => 'ecom_rider',
        'ecom_conversation' => 'ecom_conversation',
        // Payroll (docs/modules/payroll.md): setup, an employee's pay setup and item, a loan, a posted run, its payslip and a return.
        'payroll_pay_group' => 'payroll_pay_group',
        'payroll_item' => 'payroll_item',
        'payroll_employee' => 'payroll_employee',
        'payroll_employee_item' => 'payroll_employee_item',
        'payroll_loan' => 'payroll_loan',
        'payroll_run' => 'payroll_run',
        'payroll_payslip' => 'payroll_payslip',
        'payroll_return' => 'payroll_return',
        'id' => 'session', // DELETE auth/sessions/{id}
    ];

    /**
     * Route parameters that name global reference data, never a tenant row,
     * with the value to call them with. A route whose parameters are all
     * global answers the same to every tenant: it is called with this value,
     * must succeed, and must show nothing of tenant B.
     */
    public const GLOBAL_PARAMETERS = [
        'country_pack' => 'KE', // GET country-packs/{country_pack}: the published pack (CP-01)
        'type' => 'party', // GET history/{type}/{record}: a record type name from an allow-list (MD-07); the record is B's
        'document_type' => TestRequestType::KEY, // WF-01: a registered document type key; the document is B's
        'event_type' => 'core.notification.test', // GET notification-templates/{event_type}: a registered event type key (NOT-03); the texts shown are the caller's tenant's
        'kind' => TestLayoutKind::KEY, // LAY-06: config/{kind}: a registered configuration kind; the documents shown are the caller's tenant's
        'dashboard_source' => 'approvals.mine', // LAY-01: dashboard/sources/{dashboard_source}: a registered data source key; the data is the caller's own
        'marketplace_app' => TwoTenants::MARKETPLACE_APP, // MKT-01: marketplace/apps/{marketplace_app}: a listed app, global; what the page shows of the install is the caller's
        'importer' => 'customers', // IMP-01: imports/importers/{importer}/template: a registered importer key; custom field columns are the caller's tenant's
        'component' => 'inpp', // CP-01: payroll/statutory/{company}/not-applicable/{component}: a pack component code; the company is B's
    ];

    /**
     * Routes whose parameters are all global but that write rows of the
     * caller's own tenant (never global data), with why. The body check
     * (test_ids_of_tenant_b_in_request_bodies_are_refused_and_never_stored)
     * sends them B's ids.
     */
    public const TENANT_WRITES_UNDER_GLOBAL_PARAMETERS = [
        'POST api/v1/config/{kind}' => 'LAY-06: saves the draft of the caller\'s own configuration document for a key and scope; the kind is a registered key',
    ];

    /**
     * Body fields named `*_id` and which of B's ids to send in them.
     * `scope_id` follows its sibling `scope_type` (SCOPE_IDS).
     */
    public const REFERENCE_FIELDS = [
        'role_id' => 'role',
        // AUTH-11: the JIT default role and its scope (PUT sso/settings).
        'jit_role_id' => 'role',
        'jit_scope_id' => 'company',
        // AUTH-11: linking one's own account to an enabled connection (POST me/sso/link).
        'connection_id' => 'sso_live_connection',
        'company_id' => 'company',
        'branch_id' => 'branch',
        'location_id' => 'location',
        'device_id' => 'device',
        'user_id' => 'user',
        'invitation_id' => 'invitation',
        'assignment_id' => 'assignment',
        'tax_code_id' => 'tax_code',
        'price_list_id' => 'price_list',
        'party_id' => 'party', // WF-01: a credit limit change's party
        'assign_to_company_id' => 'company',
        'category_id' => 'item_category',
        'parent_id' => 'item_category_parent',
        'base_uom_id' => 'uom',
        'uom_id' => 'uom_box', // an item's other unit or a barcode's unit; base_uom_id is EA
        'tax_category_id' => 'tax_category',
        'item_id' => 'item', // MD-03 follow-up: the item a price is for
        // Inventory: a transfer's two ends, a batch, a variant's parent.
        'from_location_id' => 'location',
        'to_location_id' => 'inventory_location',
        'batch_id' => 'inventory_batch',
        'parent_item_id' => 'inventory_item',
        'owner_user_id' => 'user', // MD-05: a dimension's owner (APR-02)
        'document_id' => 'document', // AUTO-04: test a rule against a real document (the test type's)
        'rule_id' => 'automation_rule', // AUTO-04: test an edited rule with its stored webhook addresses
        'from_user_id' => 'user', // APR-06: reassign from a pending approver
        'to_user_id' => 'user', // APR-06: reassign to, or delegate to, a user
        'manager_user_id' => 'user', // AUTH-08: the manager authorising an override (the owner)
        'cashier_user_id' => 'manager', // AUTH-08: the cashier the override is for
        'payment_method_id' => 'payment_method', // payments: the method a till asks money through
        'payment_intent_id' => 'payment_intent', // payments: the payment a received amount is matched to
        // POS-09: ids a till uploads (the device's own shift, sale and line; users; master data).
        'shift_id' => 'pos_shift',
        'sale_id' => 'pos_sale',
        'sale_line_id' => 'pos_sale_line',
        'cashier_id' => 'user',
        'opened_by_id' => 'user',
        'closed_by_id' => 'user',
        'voided_by_id' => 'user',
        'number_range_id' => 'pos_number_range', // NUM-02, M1: the range the till numbered from
        'customer_id' => 'customer',
        'gift_card_item_id' => 'gift_card_item', // LOY-01, LOY-21: the service item a till sells gift cards as
        'item_id' => 'item',
        // Accounting: accounts, banks, dimensions, the intercompany counterparty and the invoice a credit note names.
        'account_id' => 'acc_account',
        'bank_account_id' => 'acc_bank_account',
        'cost_centre_id' => 'cost_centre',
        'department_id' => 'department',
        'project_id' => 'project',
        'counterparty_company_id' => 'company',
        'credits_document_id' => 'acc_invoice',
        // Procurement: an approved requisition and its line, an issued RFQ and its line, a tender criterion, an approved PO and its line.
        'requisition_id' => 'procurement_requisition_approved',
        'requisition_line_id' => 'procurement_requisition_line',
        'rfq_id' => 'procurement_rfq_issued',
        'rfq_line_id' => 'procurement_rfq_line',
        'criterion_id' => 'procurement_criterion',
        'order_id' => 'procurement_order_approved',
        'order_line_id' => 'procurement_order_line',
        // Stores: the issue line a return gives back to (an issue's requisition_line_id: ROUTE_REFERENCE_FIELDS).
        'issue_line_id' => 'stores_issue_line',
        // Manufacturing: a BOM version's output unit, a published version an order takes, an order's material,
        // by-product and issue (an issue line: ROUTE_REFERENCE_FIELDS).
        'output_uom_id' => 'uom',
        'bom_version_id' => 'mfg_bom_version_published',
        'copy_from_id' => 'mfg_bom_version_published',
        'material_id' => 'mfg_material',
        'output_id' => 'mfg_output',
        'issue_id' => 'mfg_issue',
        // HR: employees (and line managers, peers), their leave types, positions, rotations, shifts and templates.
        'employee_id' => 'hr_employee',
        'reports_to_id' => 'hr_employee',
        'peer_employee_id' => 'hr_employee_peer',
        'leave_type_id' => 'hr_leave_type',
        'position_id' => 'hr_position',
        'rotation_id' => 'hr_rotation',
        'roster_shift_id' => 'hr_roster_shift',
        'peer_roster_shift_id' => 'hr_roster_shift_peer',
        'template_id' => 'hr_checklist_template',
        'reviewer_id' => 'user',
        // Fixed assets: custodians (users, HR employees), suppliers and vendors, transfers' targets, schedules, labels.
        'custodian_user_id' => 'user',
        'custodian_employee_id' => 'hr_employee',
        'supplier_id' => 'party',
        'to_custodian_user_id' => 'user',
        'to_custodian_employee_id' => 'hr_employee',
        'to_cost_centre_id' => 'cost_centre',
        'schedule_id' => 'fa_schedule',
        'vendor_id' => 'party',
        'asset_id' => 'fa_asset',
        // CRM: owners and leaders (users), teams, sources and reasons, pipelines and stages, linked records, a target's subject.
        'owner_id' => 'user',
        'leader_id' => 'user',
        'team_id' => 'crm_team',
        'default_team_id' => 'crm_team',
        'source_id' => 'crm_source',
        'reason_id' => 'crm_lost_reason',
        'pipeline_id' => 'crm_pipeline',
        'stage_id' => 'crm_stage',
        'lead_id' => 'crm_lead',
        'opportunity_id' => 'crm_opportunity',
        'quote_id' => 'crm_quote',
        'subject_id' => 'user',
        // Payroll: an employee's pay setup and a pay group.
        'payroll_employee_id' => 'payroll_employee',
        'pay_group_id' => 'payroll_pay_group',
        // E-commerce: a store, its products and zones, a pickup point, a rider, a delivery, the tax code of the delivery fee.
        'store_id' => 'ecom_store',
        'product_id' => 'ecom_product',
        'zone_id' => 'ecom_zone',
        'pickup_location_id' => 'location',
        'rider_id' => 'ecom_rider',
        'delivery_id' => 'ecom_delivery',
        'delivery_tax_code_id' => 'tax_code',
        'scope_id' => null,
    ];

    /**
     * Writes whose control with A's own ids passes validation and the
     * permission check but is refused for a business reason of the fixture
     * ("METHOD uri" => [error code, why]).
     */
    public const CONTROL_REFUSALS = [
        'POST api/v1/crm/opportunities/{crm_opportunity}/lose' => ['opportunity_closed', 'CRM-15: the win route, called before it with A\'s ids, won the fixture opportunity, so losing it is refused after validation'],
        'POST api/v1/stores/stock-entries' => ['inventory_active', 'STR-07, STR-08: the fixture has Inventory active, which holds the stores\' stock, so own-ledger entries are refused after validation'],
        'POST api/v1/manufacturing/boms/{mfg_bom}/versions' => ['draft_exists', 'MFG-03: the fixture BOM has its draft open (one draft per BOM); the version copied from is checked first'],
        'PUT api/v1/manufacturing/orders/{mfg_order}' => ['document_not_open', 'MFG-10: the fixture order is running, so it no longer changes; its unit is checked first'],
        'POST api/v1/manufacturing/revalue' => ['nothing_to_revalue', 'MFG-15 (review fix 5): the fixture issue is not stuck (valued, or its order keeps no stock), so there is nothing to move again'],
        'POST api/v1/fixed-assets/assets/{fa_asset}/transfers' => ['transfer_pending', 'FA-14: the fixture\'s asset already has a transfer waiting for approval, so another is refused after validation'],
        'POST api/v1/fixed-assets/assets/{fa_asset}/disposals' => ['transfer_pending', 'FA-17: the fixture\'s asset has a transfer waiting for approval, so a disposal is refused after validation'],
        'POST api/v1/fixed-assets/runs' => ['disposals_open', 'FA-12: the fixture\'s disposal dated in February is still open, so February\'s run is refused after validation'],
    ];

    /**
     * REFERENCE_FIELDS overrides for routes where a field names another kind
     * of row ("METHOD uri" => field => which of B's ids).
     */
    public const ROUTE_REFERENCE_FIELDS = [
        'POST api/v1/companies/{company}/departments' => ['parent_id' => 'department_parent'],
        'PATCH api/v1/departments/{department}' => ['parent_id' => 'department_parent'],
        'POST api/v1/companies/{company}/cost-centres' => ['parent_id' => 'cost_centre_parent'],
        'PATCH api/v1/cost-centres/{cost_centre}' => ['parent_id' => 'cost_centre_parent'],
        'POST api/v1/companies/{company}/projects' => ['parent_id' => 'project_parent'],
        'PATCH api/v1/projects/{project}' => ['parent_id' => 'project_parent'],
        // SUB-04: a saved payment method of the subscription (never a till's payment method).
        'POST api/v1/subscription/invoices/{billing_invoice}/pay' => ['payment_method_id' => 'billing_payment_method'],
        // Accounting: a parent is a heading account; allocations name invoices.
        'POST api/v1/companies/{company}/accounting/accounts' => ['parent_id' => 'acc_header'],
        'PATCH api/v1/accounting/accounts/{acc_account}' => ['parent_id' => 'acc_header'],
        'POST api/v1/companies/{company}/accounting/payments' => ['document_id' => 'acc_invoice'],
        'POST api/v1/accounting/payments/{acc_payment}/allocate' => ['document_id' => 'acc_invoice'],
        'POST api/v1/accounting/credit-notes/{acc_credit_note}/allocate' => ['document_id' => 'acc_invoice'],
        // HR-18: a planned shift names an HR shift (POS uploads' shift_id is the till's).
        'POST api/v1/hr/roster-shifts' => ['shift_id' => 'hr_shift'],
        'PATCH api/v1/hr/roster-shifts/{hr_roster_shift}' => ['shift_id' => 'hr_shift'],
        // PAY-06: an employee's item names a payroll item (REFERENCE_FIELDS' item_id is a core item).
        'POST api/v1/payroll/employees/{payroll_employee}/items' => ['item_id' => 'payroll_item'],
        // Stores: an issue takes from a line of the store requisition (REFERENCE_FIELDS' requisition_line_id is Procurement's).
        'POST api/v1/stores/requisitions/{stores_requisition}/issues' => ['requisition_line_id' => 'stores_requisition_line'],
        // Manufacturing: a return gives back lines of an order's issue (REFERENCE_FIELDS' issue_line_id is Stores').
        'POST api/v1/manufacturing/orders/{mfg_order}/returns' => ['issue_line_id' => 'mfg_issue_line'],
        // Manufacturing: the stuck document moved and valued again (an issue of the fixture).
        'POST api/v1/manufacturing/revalue' => ['document_id' => 'mfg_issue'],
        // Fixed assets: `category_id` names an asset category (FA-02), not an item category.
        'POST api/v1/fixed-assets/assets' => ['category_id' => 'fa_category'],
        'PATCH api/v1/fixed-assets/assets/{fa_asset}' => ['category_id' => 'fa_category'],
        'POST api/v1/fixed-assets/item-categories' => ['category_id' => 'fa_category'],
        // CRM-15: winning takes a won reason (REFERENCE_FIELDS' reason_id is a lost reason).
        'POST api/v1/crm/opportunities/{crm_opportunity}/win' => ['reason_id' => 'crm_won_reason'],
    ];

    /**
     * MD-07: every history type (HistoryTypes::names()) => which of B's ids
     * is a record of that type. A type registered without an entry here
     * fails the suite.
     */
    public const HISTORY_TYPES = [
        'party' => 'party',
        'tax_code' => 'tax_code',
        'tax_category' => 'tax_category',
        'price_list' => 'price_list',
        'item' => 'item',
        'item_category' => 'item_category',
        'uom' => 'uom',
        'payment_method' => 'payment_method',
        'department' => 'department',
        'cost_centre' => 'cost_centre',
        'project' => 'project',
        'company' => 'company',
        'branch' => 'branch',
        'location' => 'location',
        'user' => 'user',
        'role' => 'role',
        'custom_field' => 'custom_field',
        'custom_form_type' => 'custom_form_type',
        'hr_employee' => 'hr_employee',
        'hr_disciplinary_case' => 'hr_disciplinary_case',
    ];

    /** scope_type => which of B's ids goes in scope_id. */
    public const SCOPE_IDS = ['tenant' => 'tenant', 'company' => 'company', 'branch' => 'branch', 'location' => 'location', 'scope_id' => 'company'];

    /** Fields ending in `_id` that are not references to rows, with why. */
    public const NOT_REFERENCES = [
        'tax_id' => "a company's tax registration number, free text",
        'client_id' => 'API-02 (ADR 024): an OAuth client id, a global marketplace client looked up under its own policy, never a tenant row',
        'sms_sender_id' => 'BR-06: the alphanumeric SMS sender name (3 to 11 letters), free text, never a row',
        'external_id' => 'HR-24: a biometric device\'s own event id, free text the device names; looked up only for that device under its tenant, never a reference to another row',
        'client_id' => 'AUTH-11: an identity provider\'s OAuth client id, free text registered at the IdP, never a row',
        'directory_id' => 'AUTH-11: an Entra ID directory (tenant) GUID at Microsoft, never a row',
        'idp_entity_id' => 'AUTH-11: a SAML identity provider\'s entity id (a URI it names itself), free text, never a row',
        'session_id' => 'AUTH-07: a till sign-in session the device names itself (actor_proof); looked up only for that device under its tenant, never a reference to another row',
    ];

    /**
     * The queries every list route is called with (routes with ids too, with
     * B's ids and, as a control, A's). The filters match rows both tenants
     * have (TwoTenants enters USD/KES shop rates in each).
     */
    public const LIST_QUERIES = [
        [],
        ['status' => 'all', 'per_page' => 200],
        ['format' => 'csv'],
        ['pair' => 'USD/KES', 'from' => '2000-01-01', 'to' => '2100-12-31', 'kind' => 'shop'],
        // MD-01: both tenants have a VIP customer named "Customer A|B".
        ['search' => 'Customer', 'role' => 'customer', 'tag' => 'vip'],
        // MD-02: both tenants have a stock item "Item A|B" with this barcode.
        ['search' => 'Item', 'type' => 'stock', 'barcode' => '6161000000001'],
        // EXP-01: list exports (items and parties); the sort and columns both lists have.
        ['format' => 'csv', 'status' => 'all', 'sort' => '-created_at', 'columns' => ['name']],
        // NOT-06: the delivery log's channel filter (both tenants sent email).
        ['channel' => 'email', 'status' => 'all'],
        // NFR-08: a customer's marketing consent (subject_id is sent with B's and A's ids through LIST_ID_QUERIES).
        ['subject_type' => 'customer'],
        // AUTO-05: the run log's outcome filter (both tenants have a run that succeeded).
        ['outcome' => 'succeeded'],
        // API-03: requests per day over 7 days (both tenants used their key).
        ['days' => 7],
        // MD-03 follow-up: a price list's prices in force (both tenants priced their item).
        ['state' => 'current'],

        // APR-04: the approvals inbox's oversight view and overdue filter.
        ['view' => 'all', 'status' => 'all', 'overdue' => '0'],
        // NFR-04: a device pull of some entities from the start, one row per page.
        ['entities' => ['items', 'customers', 'staff', 'exchange_rates'], 'cursors' => ['items' => '', 'customers' => ''], 'limit' => 1],
        // M3, H2: review filters (sales and the held list).
        ['flagged' => '1', 'flag' => 'actor_unverified', 'reviewed' => '0', 'kind' => 'refund'],
        // CF-01..CF-03: definitions and the form schema of items, lookup candidates
        // (both tenants have a supplier), and items filtered by a custom field (both set red).
        ['entity' => 'item', 'target' => 'party', 'search' => 'Supplier'],
        ['custom' => ['colour' => 'red']],
        // TEN-07: the consolidated sales of a year in a reporting currency (both tenants sold in KES).
        ['from' => '2026-01-01', 'to' => '2026-12-31', 'currency' => 'USD'],
        // LAY-06: a configuration key (both tenants have a layout under the default key).
        ['key' => 'default'],
        // LAY-06: one scope of a key (scope_id is sent with B's and A's ids through LIST_ID_QUERIES).
        ['key' => 'default', 'scope_type' => 'tenant'],
        // LAY-03: one form's fields and default layout.
        ['form' => 'item'],
        // IMP-02: imports of one importer (both tenants imported customers).
        ['importer' => 'customers'],
        // IMP-01: a template in French.
        ['locale' => 'fr'],
        // LOY-18: the ageing report at a date (both tenants charged a customer on account).
        ['as_of' => '2100-01-01'],
        // ACC-08: a fiscal year's periods (both tenants have 2026).
        ['year' => '2026'],
        // RBAC-04 (security review 5): the roles the user may grant, anywhere or tenant-wide, for an invite
        // (a place of tenant B's is refused as not found: GrantableRolesTest).
        ['grantable_at' => 'any'],
        ['grantable_at' => 'tenant', 'grantable_for' => 'invite'],
        // STR-12, STR-14: consumption by cost centre (both tenants issued to theirs), the issue voucher.
        ['group' => 'cost_centre', 'document' => 'issue_voucher'],
        // MFG-05: an explosion for a quantity at a moment.
        ['qty' => '2', 'at' => '2026-12-31T00:00:00Z'],
        // FA-12: a month of depreciation (both tenants posted January 2026).
        ['period' => '2026-01'],
        // PAY-05, PAY-14: employees not in a pay group, run lines with errors.
        ['unassigned' => '1', 'errors' => '1'],
    ];

    /**
     * Query parameters that take a row id (`?category=` on items, MD-02;
     * `?company=` on tax categories, MD-03) => which id. The list check
     * sends B's id, and A's as a control (idQueries()).
     */
    public const LIST_ID_QUERIES = ['category' => 'item_category', 'company' => 'company', 'party' => 'customer', 'rule' => 'automation_rule', 'branch' => 'branch', 'location' => 'location', 'id' => 'party', 'scope_id' => 'company', 'item' => 'item', 'account' => 'acc_account', 'cost_centre' => 'cost_centre', 'department' => 'department', 'source' => 'inventory_adjustment', 'employee' => 'hr_employee', 'order' => 'mfg_order', 'uom_id' => 'uom', 'pay_group' => 'payroll_pay_group', 'run' => 'payroll_run', 'owner' => 'user', 'pipeline' => 'crm_pipeline', 'asset' => 'fa_asset', 'custodian' => 'user', 'store' => 'ecom_store', 'rider' => 'ecom_rider', 'webhook' => 'webhook', 'subject_id' => 'customer'];

    /** Query parameters LIST_QUERIES and LIST_ID_QUERIES cover; `page` only pages through the same rows. */
    public const LIST_QUERY_PARAMETERS = ['status', 'per_page', 'page', 'format', 'pair', 'from', 'to', 'kind', 'search', 'role', 'tag', 'type', 'barcode', 'category', 'company', 'sort', 'columns', 'channel', 'view', 'overdue', 'party', 'outcome', 'rule', 'state', 'entities', 'cursors', 'limit', 'branch', 'location', 'flagged', 'flag', 'reviewed', 'currency', 'key', 'entity', 'target', 'id', 'custom', 'scope_type', 'scope_id', 'form', 'importer', 'locale', 'item', 'account', 'cost_centre', 'year', 'as_of', 'department', 'group', 'document', 'source', 'employee', 'qty', 'at', 'order', 'uom_id', 'pay_group', 'unassigned', 'errors', 'run', 'owner', 'pipeline', 'asset', 'custodian', 'period', 'store', 'rider', 'payment_status', 'fulfilment', 'days', 'webhook', 'subject_type', 'subject_id',
        // MKT-02: the OAuth authorization request of the consent screen (no tenant ids).
        'client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method',
        // RBAC-04: role pickers' grantable roles.
        'grantable_at', 'grantable_for'];

    private TwoTenants $tenants;

    /** @var array<string, list<string>> */
    private array $identifiers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AUTH-06..AUTH-08: till permissions for the staff, PIN and override routes.
        $this->registerTillModule();
        $this->tenants = TwoTenants::build($this);
        app(TenantContext::class)->set(null);
        // EXP-01: the suite exports every list many times a minute; the
        // limit itself is tested in ListSortAndExportTest.
        RateLimiter::for(ListExport::EXPORT_LIMITER, fn () => Limit::none());
        // NFR-04: the suite calls the device routes far more than a till would.
        RateLimiter::for('device-sync', fn () => Limit::none());
        RateLimiter::for('device-secret', fn () => Limit::none());
        RateLimiter::for('loyalty-lookup', fn () => Limit::none());
        // PAY-20, PAY-21: the suite builds payment and return files far more often than a person would.
        RateLimiter::for('payroll-files', fn () => Limit::none());
    }

    // ---- Database ---------------------------------------------------------

    public function test_the_suite_runs_as_the_rls_bound_app_role(): void
    {
        $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertSame('app', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_in_tenant_a_context_no_table_shows_a_row_of_tenant_b(): void
    {
        $a = $this->tenants->a->tenantId;
        $b = $this->tenants->b->tenantId;

        foreach ($this->tenantTables() as $table) {
            // ADR 016: rows only in the operator tenant; customers see none of them.
            if (isset(GlobalTables::OPERATOR[$table])) {
                $operator = (string) app(DueTenants::class)->operator();
                $this->assertGreaterThan(0, $this->asTenant($operator, fn () => $this->rows("select count(*) from \"{$table}\"")), "{$table}: the operator has no rows; extend tests/Support/TwoTenants.php");
                $this->assertSame(0, $this->asTenant($a, fn () => $this->rows("select count(*) from \"{$table}\"")), "{$table}: tenant A sees the operator's rows");
                $this->assertSame(0, $this->asTenant($b, fn () => $this->rows("select count(*) from \"{$table}\"")), "{$table}: tenant B sees the operator's rows");

                continue;
            }

            $inB = $this->asTenant($b, fn () => $this->rows("select count(*) from \"{$table}\""));
            $this->assertGreaterThan(0, $inB, "{$table}: tenant B has no rows, so isolation is not proven; extend tests/Support/TwoTenants.php");

            $this->asTenant($a, function () use ($table, $a, $b) {
                $this->assertSame(0, $this->rows("select count(*) from \"{$table}\" where tenant_id = ?", [$b]), "{$table}: tenant A sees rows of tenant B");

                $all = $this->rows("select count(*) from \"{$table}\"");
                $this->assertGreaterThan(0, $all, "{$table}: tenant A has no rows, so isolation is not proven; extend tests/Support/TwoTenants.php");
                $this->assertSame($this->rows("select count(*) from \"{$table}\" where tenant_id = ?", [$a]), $all, "{$table}: tenant A sees rows that are not its own");
            });
        }

        // tenants is keyed on id rather than tenant_id.
        $this->asTenant($a, function () use ($a, $b) {
            $this->assertSame(0, $this->rows('select count(*) from tenants where id = ?', [$b]), 'tenants: tenant A sees tenant B');
            $this->assertSame(1, $this->rows('select count(*) from tenants where id = ?', [$a]));
            $this->assertSame(1, $this->rows('select count(*) from tenants'), 'tenants: tenant A sees another tenant');
        });
    }

    public function test_without_a_tenant_context_every_tenant_table_is_empty(): void
    {
        app(TenantContext::class)->set(null);
        $this->assertSame('', DB::selectOne("select coalesce(current_setting('app.tenant_id', true), '') as id")->id);

        foreach ([...$this->tenantTables(), 'tenants'] as $table) {
            $this->assertSame(0, $this->rows("select count(*) from \"{$table}\""), "{$table}: rows are visible without a tenant context");
        }
    }

    // ---- Routes -----------------------------------------------------------

    public function test_every_api_route_is_authenticated_or_allow_listed_as_public(): void
    {
        $seen = [];

        foreach ($this->apiRoutes() as $route) {
            foreach ($this->methods($route) as $method) {
                $key = "{$method} {$route->uri()}";
                $seen[] = $key;

                if (! isset(self::PUBLIC_ROUTES[$key])) {
                    // ADR 013: staff routes take only a platform token (auth:platform), never a tenant one.
                    $guard = $this->isPlatform($route) ? 'auth:platform' : 'auth:sanctum';
                    $this->assertContains($guard, $route->gatherMiddleware(), "{$key} has no {$guard} authentication and is not in PUBLIC_ROUTES");
                    $this->assertNotContains($this->isPlatform($route) ? 'auth:sanctum' : 'auth:platform', $route->gatherMiddleware(), "{$key} mixes tenant and platform authentication");
                }
            }
        }

        foreach (array_keys(self::PUBLIC_ROUTES) as $key) {
            $this->assertContains($key, $seen, "PUBLIC_ROUTES lists {$key}, which no longer exists");
        }
    }

    public function test_every_route_with_ids_refuses_tenant_b_ids_and_changes_nothing_of_b(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $before = $this->snapshot($b->tenantId);
        $called = 0;

        foreach ($this->apiRoutes() as $route) {
            // Staff routes hold no tenant data and refuse tenant tokens (PlatformAuthTest); a tenant token is 401 there.
            if ($this->isPlatform($route)) {
                $this->assertSame(401, $this->json($this->methods($route)[0], $this->uriWith($route, $b, platform: true), [], $a->bearer())->getStatusCode(), "{$route->uri()} accepted a tenant token");

                continue;
            }

            if ($route->parameterNames() === [] || $this->isPublic($route)) {
                continue;
            }

            if ($this->hasOnlyGlobalParameters($route)) {
                if (array_diff(array_map(fn ($m) => "{$m} {$route->uri()}", $this->methods($route)), array_keys(self::TENANT_WRITES_UNDER_GLOBAL_PARAMETERS)) === []) {
                    continue;
                }

                $this->assertSame(['GET'], $this->methods($route), "{$route->uri()} changes global data through the tenant API");

                foreach (self::LIST_QUERIES as $query) {
                    $uri = $this->uriWith($route, $a).($query === [] ? '' : '?'.http_build_query($query));
                    $response = $this->json('GET', $uri, [], $a->bearer());
                    $query === [] ? $response->assertOk() : $this->assertContains($response->getStatusCode(), [200, 422], "GET {$uri} answered {$response->getStatusCode()}");
                    $this->assertBodyHasNothingOf($b, $response, "GET {$uri}");
                    $called++;
                }

                continue;
            }

            foreach ($this->methods($route) as $method) {
                // A GET is also called with every list query (filters included).
                foreach ($method === 'GET' ? self::LIST_QUERIES : [[]] as $query) {
                    // Every list query is sent with B's and then A's ids, more than a per-user
                    // `throttle:60,1` route allows a minute: the suite's own volume is never a 429.
                    RateLimiter::clear(sha1((string) $a->id('user')));
                    $suffix = $query === [] ? '' : '?'.http_build_query($query);
                    $uri = $this->uriWith($route, $b).$suffix;
                    $response = $this->json($method, $uri, $method === 'GET' ? [] : $this->hijackBody($b), $this->bearerFor($route, $a));
                    $called++;

                    // 404 exactly: A's Owner holds every permission, so a 403 here would hide whether the id was resolved.
                    $this->assertSame(404, $response->getStatusCode(), "{$method} {$uri} with tenant B's ids answered {$response->getStatusCode()} to tenant A: {$response->getContent()}");
                    $this->assertBodyHasNothingOf($b, $response, "{$method} {$uri}");

                    // Control: the same GET with A's own ids works (a route may refuse
                    // another list's filter, but never as not found), so the 404 above
                    // is isolation, not a bad URL.
                    if ($method === 'GET') {
                        $control = $this->json('GET', $this->uriWith($route, $a).$suffix, [], $this->bearerFor($route, $a));
                        $query === [] ? $this->assertSame(200, $control->getStatusCode(), "GET {$this->uriWith($route, $a)} with A's own ids answered {$control->getStatusCode()}: {$control->getContent()}") : $this->assertContains($control->getStatusCode(), [200, 422], "GET {$this->uriWith($route, $a)}{$suffix} answered {$control->getStatusCode()}");
                        $this->assertBodyHasNothingOf($b, $control, "GET {$this->uriWith($route, $a)}{$suffix}");
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $called);
        $this->assertSame($before, $this->snapshot($b->tenantId), "tenant B's rows changed after tenant A called its routes with B's ids");

        // Control: the filter query really selects A's rows on the rate history.
        $filtered = $this->json('GET', "/api/v1/companies/{$a->id('company')}/exchange-rates?".http_build_query(self::LIST_QUERIES[3]), [], $a->bearer())->assertOk();
        $this->assertCount(2, $filtered->json('data'));

        // Control: the party search really selects A's customer (and nothing of B, checked above).
        $parties = $this->json('GET', '/api/v1/parties?'.http_build_query(self::LIST_QUERIES[4]), [], $a->bearer())->assertOk();
        $this->assertSame([$a->id('customer')], array_column($parties->json('data'), 'id'));

        // Control: the item search and barcode lookup really select A's item.
        $items = $this->json('GET', '/api/v1/items?'.http_build_query(self::LIST_QUERIES[5]), [], $a->bearer())->assertOk();
        $this->assertSame([$a->id('item')], array_column($items->json('data'), 'id'));

        // `?category=` takes an id: B's category is refused, A's selects A's item (and its subcategories').
        $refused = $this->json('GET', "/api/v1/items?category={$b->id('item_category_parent')}", [], $a->bearer());
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertBodyHasNothingOf($b, $refused, 'GET items?category= with B\'s category');
        $own = $this->json('GET', "/api/v1/items?category={$a->id('item_category_parent')}", [], $a->bearer())->assertOk();
        $this->assertSame([$a->id('item')], array_column($own->json('data'), 'id'));
    }

    public function test_list_routes_show_nothing_of_tenant_b_to_the_owner_the_branch_manager_or_a_device(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $okForOwner = 0;

        foreach ($this->apiRoutes() as $route) {
            if ($route->parameterNames() !== [] || $this->isPublic($route) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            foreach (['owner', 'manager', 'device'] as $who) {
                foreach ([...self::LIST_QUERIES, ...$this->idQueries($route, $a, $b)] as $query) {
                    $uri = '/'.$route->uri().($query === [] ? '' : '?'.http_build_query($query));
                    $response = $this->get($uri, $a->bearer($who));

                    $this->assertBodyHasNothingOf($b, $response, "GET {$uri} as A's {$who}");

                    if ($who === 'owner' && $query === [] && $response->getStatusCode() === 200) {
                        $okForOwner++;
                    }
                }
            }
        }

        $this->assertGreaterThan(5, $okForOwner, 'the owner should be able to read most lists; the check above proves nothing otherwise');

        // Controls: the manager and the device really read data in the checks above.
        foreach (['/api/v1/me', '/api/v1/me/permissions', '/api/v1/locations'] as $uri) {
            $this->get($uri, $a->bearer('manager'))->assertOk();
        }
        $this->assertNotEmpty($this->get('/api/v1/locations', $a->bearer('manager'))->json('data'));
        $this->get('/api/v1/devices/me', $a->bearer('device'))->assertOk()->assertJsonPath('data.id', $a->id('device'));
        // Control: filtering by A's own category finds A's item, by B's finds nothing (MD-02).
        $this->assertNotEmpty($this->get('/api/v1/items?category='.$a->id('item_category'), $a->bearer('owner'))->assertOk()->json('data'));
        $this->get('/api/v1/items?category='.$b->id('item_category'), $a->bearer('owner'))->assertUnprocessable();
        // Control: tax categories of A's own company are listed, B's company is refused (MD-03).
        $this->assertNotEmpty($this->get('/api/v1/tax-categories?status=all&company='.$a->id('company'), $a->bearer('owner'))->assertOk()->json('data'));
        $this->get('/api/v1/tax-categories?company='.$b->id('company'), $a->bearer('owner'))->assertUnprocessable();
    }

    /** TEN-07: the consolidated sales of today hold A's sale, in KES and USD, and nothing of B. */
    public function test_pos_insights_of_tenant_a_show_nothing_of_tenant_b(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $today = now('Africa/Nairobi')->toDateString();

        foreach (['owner', 'manager'] as $who) {
            $response = $this->getJson("/api/v1/pos/insights?from={$today}&to={$today}&currency=USD", $a->bearer($who))->assertOk();
            $this->assertBodyHasNothingOf($b, $response, "GET pos/insights as A's {$who}");
        }

        // Control: A's owner sees A's own sales.
        $own = $this->getJson("/api/v1/pos/insights?from={$today}&to={$today}", $a->bearer())->assertOk();
        $this->assertGreaterThan(0, $own->json('data.sales_count'));
        $this->assertSame([$a->id('company')], array_column(array_column($own->json('data.companies'), 'company'), 'id'));
        $this->get("/api/v1/pos/insights?from={$today}&to={$today}&company={$b->id('company')}", ['Accept' => 'application/json', ...$a->bearer()])->assertUnprocessable();
    }

    /**
     * Code-level guard: every query parameter a GET route reads (its Form
     * Request rules, and query()/input()/... calls in the request class and
     * the controller method) must be one the list check above exercises. A
     * new `?search=` or filter therefore fails here until the list check
     * calls it with B's values.
     */
    public function test_list_routes_read_only_query_parameters_the_suite_exercises(): void
    {
        $checked = 0;

        foreach ($this->apiRoutes() as $route) {
            // Routes with ids are listed too: they take the same queries (with B's and A's ids) above.
            // Platform routes (ADR 013) read global data and refuse tenant tokens.
            if ($this->isPublic($route) || $this->isPlatform($route) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            foreach ($this->queryParametersRead($route) as $parameter) {
                $this->assertContains($parameter, self::LIST_QUERY_PARAMETERS, sprintf(
                    'GET %s reads the query parameter [%s], which the isolation list check never sends: add it to %s::LIST_QUERY_PARAMETERS and to the queries of test_list_routes_show_nothing_of_tenant_b_to_the_owner_the_branch_manager_or_a_device (with tenant B\'s values for a search or filter).',
                    $route->uri(), $parameter, self::class,
                ));
            }
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }

    public function test_every_history_type_refuses_tenant_b_records(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $types = app(HistoryTypes::class)->names();
        $this->assertNotEmpty($types);

        foreach ($types as $type) {
            $this->assertArrayHasKey($type, self::HISTORY_TYPES, sprintf(
                'The history type [%s] has no fixture: add it to %s::HISTORY_TYPES and give both tenants a record of that type in tests/Support/TwoTenants.php.',
                $type, self::class,
            ));
            $fixture = self::HISTORY_TYPES[$type];

            $refused = $this->getJson("/api/v1/history/{$type}/{$b->id($fixture)}", $a->bearer());
            $this->assertSame(404, $refused->getStatusCode(), "GET history/{$type} with tenant B's record answered {$refused->getStatusCode()}");
            $this->assertBodyHasNothingOf($b, $refused, "GET history/{$type} with B's record");

            // Control: A's own record has a history, and it shows nothing of B.
            $own = $this->getJson("/api/v1/history/{$type}/{$a->id($fixture)}", $a->bearer())->assertOk();
            $this->assertNotEmpty($own->json('data'), "history/{$type} of A's own record is empty, so the check proves nothing");
            $this->assertBodyHasNothingOf($b, $own, "GET history/{$type} with A's record");
        }
    }

    // ---- Ids in request bodies -------------------------------------------

    /**
     * Foreign keys are checked by PostgreSQL without row-level security, so
     * a body naming B's role or location could be stored in A's rows. Every
     * mutating route is called on A's own resources (it passes binding) with
     * each id field of its Form Request set to one of B's ids: always 404 or
     * 422, and afterwards no A row references anything of B.
     */
    public function test_ids_of_tenant_b_in_request_bodies_are_refused_and_never_stored(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $before = $this->snapshot($b->tenantId);
        $hijacked = [];

        foreach ($this->apiRoutes() as $route) {
            // Platform routes (ADR 013) refuse tenant tokens outright (checked in the route-id test).
            if ($this->isPublic($route) || $this->isPlatform($route)) {
                continue;
            }

            foreach (array_diff($this->methods($route), ['GET']) as $method) {
                $key = "{$method} {$route->uri()}";
                $idFields = $this->idFields($this->ruleKeys($route, $method, $a));

                if ($idFields === []) {
                    continue;
                }

                $base = $this->bodyFor($key, $a);
                $this->assertNotNull($base, "{$key} takes ids in its body ({$this->list($idFields)}): add a valid body for it to bodyFor() so the isolation suite can send B's ids");
                $uri = $this->uriWith($route, $a);

                foreach ($this->hijackVariants($base, $idFields, $b, self::ROUTE_REFERENCE_FIELDS[$key] ?? []) as $label => $body) {
                    $response = $this->json($method, $uri, $body, $this->bearerFor($route, $a));
                    $this->assertContains($response->getStatusCode(), [404, 422], "{$key} with {$label} of tenant B answered {$response->getStatusCode()}: {$response->getContent()}");
                    $this->assertBodyHasNothingOf($b, $response, "{$key} with {$label}");
                    $hijacked[$key][] = $label;
                }

                // Control: the same body with A's own ids is accepted, so the refusals are about B's ids
                // (or passes validation and is refused for a documented business reason, CONTROL_REFUSALS).
                $control = $this->json($method, $uri, $base, $this->bearerFor($route, $a));
                isset(self::CONTROL_REFUSALS[$key])
                    ? $this->assertSame(self::CONTROL_REFUSALS[$key][0], $control->json('code'), "{$key} control with A's own ids answered {$control->getStatusCode()}: {$control->getContent()}")
                    : $this->assertTrue($control->isSuccessful(), "{$key} control with A's own ids answered {$control->getStatusCode()}: {$control->getContent()}");
            }
        }

        $this->assertArrayHasKey('POST api/v1/invitations', $hijacked);
        $this->assertArrayHasKey('POST api/v1/users/{user}/assignments', $hijacked);
        $this->assertArrayHasKey('POST api/v1/tax-categories', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/tax-categories/{tax_category}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/parties', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/parties/{party}', $hijacked);
        $this->assertArrayHasKey('PUT api/v1/master-data/settings', $hijacked);
        $this->assertArrayHasKey('POST api/v1/items', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/items/{item}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/price-lists/{price_list}/prices', $hijacked);
        $this->assertArrayHasKey('POST api/v1/price-lists/{price_list}/prices/bulk', $hijacked);
        $this->assertArrayHasKey('POST api/v1/item-categories', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/item-categories/{item_category}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/workflows', $hijacked);
        $this->assertArrayHasKey('POST api/v1/workflows/{workflow}/copy', $hijacked);
        $this->assertArrayHasKey('POST api/v1/automation-rules', $hijacked);
        $this->assertArrayHasKey('POST api/v1/automation-rules/{automation_rule}/test', $hijacked);
        $this->assertArrayHasKey('POST api/v1/automation-templates/use', $hijacked);
        $this->assertArrayHasKey('POST api/v1/approvals/{approval}/reassign', $hijacked);
        $this->assertArrayHasKey('POST api/v1/me/delegations', $hijacked);
        $this->assertArrayHasKey('POST api/v1/pos/pin/verify', $hijacked);
        $this->assertArrayHasKey('POST api/v1/pos/pin/attempts', $hijacked);
        $this->assertArrayHasKey('POST api/v1/pos/override', $hijacked);
        $this->assertArrayHasKey('POST api/v1/pos/pin/change', $hijacked);
        $this->assertArrayHasKey('POST api/v1/payments/intents', $hijacked);
        $this->assertArrayHasKey('POST api/v1/payment-receipts/{payment_receipt}/match', $hijacked);
        $this->assertArrayHasKey('PUT api/v1/numbering/formats', $hijacked);
        $this->assertArrayHasKey('POST api/v1/config/{kind}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/config/{kind}/{config_document}/copy', $hijacked);
        foreach (['sales', 'shifts', 'cash-movements', 'voids', 'refunds'] as $upload) {
            $this->assertArrayHasKey("POST api/v1/pos/{$upload}", $hijacked);
        }
        foreach (array_keys(self::ROUTE_REFERENCE_FIELDS) as $key) {
            $this->assertArrayHasKey($key, $hijacked);
        }
        $this->assertNoRowOf($a, 'references', $b);
        $this->assertSame($before, $this->snapshot($b->tenantId), "tenant B's rows changed after tenant A sent B's ids in request bodies");
    }

    // ---- Exports ----------------------------------------------------------

    public function test_the_access_review_csv_of_tenant_a_contains_nothing_of_tenant_b(): void
    {
        $a = $this->tenants->a;

        $response = $this->get('/api/v1/access-review?format=csv', $a->bearer())->assertOk();
        $csv = $response->streamedContent();

        // Control: A's own people are in it.
        $this->assertStringContainsString('owner-a@example.com', $csv);
        $this->assertStringContainsString('manager-a@example.com', $csv);

        foreach ($this->identifiersOf($this->tenants->b) as $value) {
            $this->assertStringNotContainsString($value, $csv, "the access review export of tenant A contains {$value} of tenant B");
        }

        foreach (['Tenant B', 'Owner B', 'Manager B', 'Company B', 'Branch B', 'Outlet B', 'Clerk B'] as $name) {
            $this->assertStringNotContainsString($name, $csv);
        }
    }

    /**
     * EXP-02: the full export of tenant A, made after both tenants exist,
     * has a file for every tenant table, each with A's rows only, A's media,
     * and nothing of B. No secret of either tenant is in it: the values of
     * every denied column are looked up and searched for.
     */
    public function test_the_full_export_of_tenant_a_has_every_table_and_nothing_of_tenant_b(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $id = $this->postJson('/api/v1/tenant-exports', ['password' => TwoTenants::PASSWORD], $a->bearer())->assertStatus(202)->json('data.id');
        $export = $this->asTenant($a->tenantId, fn () => TenantExport::query()->findOrFail($id));
        $this->assertSame(TenantExport::READY, $export->status, (string) $export->failure);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('media')->path($export->path)));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($a->tenantId, $manifest['tenant']['id']);
        $this->assertSame(GlobalTables::TABLES, $manifest['omitted']['tables']);
        $this->assertEqualsCanonicalizing([...$this->tenantTables(), 'tenants'], array_column($manifest['tables'], 'name'));

        $content = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content .= $zip->getFromIndex($i)."\n";
        }

        foreach ($manifest['tables'] as $table) {
            $lines = array_values(array_filter(explode("\n", (string) $zip->getFromName($table['file']))));
            if (! isset(GlobalTables::OPERATOR[$table['name']])) {
                $this->assertGreaterThan(0, count($lines), "{$table['name']}: tenant A's rows are exported");
            }

            foreach ($lines as $line) {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($a->tenantId, $row[$table['name'] === 'tenants' ? 'id' : 'tenant_id'], "{$table['name']}: a row that is not tenant A's");
            }
        }

        // A's media (item image, approval attachment, custom form files...) are in files/.
        $this->assertGreaterThan(0, $export->file_count);
        $this->assertNotEmpty(array_filter(array_column($manifest['files'], 'path'), fn ($path) => str_starts_with($path, 'files/')));

        foreach ($this->identifiersOf($b) as $value) {
            $this->assertStringNotContainsString($value, $content, "the full export of tenant A contains {$value} of tenant B");
        }

        foreach (['Tenant B', 'Owner B', 'Manager B', 'Company B', 'Branch B', 'Outlet B', 'Customer B', 'Item B'] as $name) {
            $this->assertStringNotContainsString($name, $content);
        }

        // Secrets: the stored value of every denied column, in either tenant, and the plain M-Pesa credentials.
        // AUTO-03: webhook URLs are write-only; their tokens never leave.
        $secrets = ['key-a', 'secret-a', 'pass-a', 'key-b', 'secret-b', 'pass-b', 'hook-token-a', 'hook-token-b', 'path-token-a', 'path-token-b'];
        foreach ([$a, $b] as $tenant) {
            $this->asTenant($tenant->tenantId, function () use (&$secrets) {
                foreach (array_keys(ExportColumns::DENIED) as $key) {
                    [$table, $column] = explode('.', $key, 2);
                    array_push($secrets, ...DB::table($table)->whereNotNull($column)->pluck($column)->map(fn ($v) => (string) $v)->filter(fn ($v) => strlen($v) >= 8)->all());
                }
            });
        }
        $this->assertGreaterThan(10, count($secrets), 'the fixture has secrets to look for');

        foreach (array_unique($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, $content, 'the full export contains a secret');
        }
    }

    /**
     * EXP-01: list exports read the same tenant-bound query as the list,
     * inside the tenant's own context while streaming. CSV and the cells
     * of the Excel file are checked as text (an xlsx is a zip, a PDF is
     * compressed: their bytes prove nothing); every format answers.
     */
    public function test_list_exports_of_tenant_a_contain_nothing_of_tenant_b(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $theirs = ['Customer B', 'Supplier B', 'P00000000B', 'ITEM-B', 'Article B', 'Goods B', '+254700000302',
            'Owner B', 'Manager B', 'Cashier B', 'Clerk B', 'Retail B', 'Unit B', 'Root B', 'Outlet B', 'Branch B',
            'key-b', 'secret-b', 'pass-b', '17437b'];
        // MD-04: payment method settings and credentials never reach an export, A's own included.
        $secrets = ['key-a', 'secret-a', 'pass-a', '17437a'];

        $this->assertSame([], $this->unlistedExports($a), 'every route that exports a list (ListExport) is in exportedLists');

        // Exports offering only some formats.
        $formats = ['procurement/supplier-performance' => ['csv']];

        foreach ($this->exportedLists($a) as $list => $ours) {
            foreach ($formats[$list] ?? ['csv', 'xlsx', 'pdf'] as $format) {
                $response = $this->get("/api/v1/{$list}".(str_contains($list, '?') ? '&' : '?')."format={$format}", $a->bearer());
                $this->assertSame(200, $response->getStatusCode(), "{$list} {$format}: ".($response->baseResponse instanceof StreamedResponse ? '' : $response->getContent()));
                $body = $response->streamedContent();

                if ($format === 'pdf') {
                    $this->assertStringStartsWith('%PDF-', $body);

                    continue;
                }

                $text = $format === 'csv' ? $body : $this->xlsxText($body);

                foreach ($ours as $value) {
                    $this->assertStringContainsString($value, $text, "control: A's {$list} {$format} export lists {$value}");
                }

                foreach ([...$theirs, ...$this->identifiersOf($b)] as $value) {
                    $this->assertStringNotContainsString($value, $text, "A's {$list} {$format} export contains {$value} of tenant B");
                }

                foreach ($secrets as $value) {
                    $this->assertStringNotContainsString($value, $text, "A's {$list} {$format} export contains the credential or setting {$value}");
                }
            }
        }
    }

    /**
     * GET routes whose action takes a ListExport (EXP-01) and that no entry
     * of exportedLists() reaches, so a new export cannot skip the check.
     *
     * @return list<string> route URIs
     */
    private function unlistedExports(TenantFixture $a): array
    {
        $routes = Route::getRoutes();
        $listed = [];

        foreach (array_keys($this->exportedLists($a)) as $list) {
            $listed[] = $routes->match(Request::create('/api/v1/'.strtok($list, '?'), 'GET'))->uri();
        }

        $unlisted = [];

        foreach ($routes->getRoutes() as $route) {
            $action = $route->getActionName();

            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($route->uri(), 'api/v1/') || $action === 'Closure') {
                continue;
            }

            [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
            $exports = collect((new ReflectionMethod($class, $method))->getParameters())
                ->contains(fn ($parameter) => $parameter->getType() instanceof ReflectionNamedType && $parameter->getType()->getName() === ListExport::class);

            if ($exports && ! in_array($route->uri(), $listed, true)) {
                $unlisted[] = $route->uri();
            }
        }

        sort($unlisted);

        return $unlisted;
    }

    /**
     * Every exported list (EXP-01), with values of tenant A each export
     * must show (the control).
     *
     * @return array<string, list<string>>
     */
    private function exportedLists(TenantFixture $a): array
    {
        return [
            'items?status=all' => ['ITEM-A', 'Article A'],
            'parties?status=all' => ['Customer A', 'Supplier A', '+254700000301'],
            'users' => ['Owner A', 'Manager A', 'Branch Manager at Branch A'],
            'invitations' => ['Cashier A', 'Cashier at Outlet A'],
            'auth/sessions' => [],
            'roles?status=all' => ['Clerk A', 'Branch Manager'],
            "users/{$a->id('manager')}/assignments" => ['Clerk A', 'Outlet A'],
            "companies/{$a->id('company')}/exchange-rates" => ['USD/KES', '129.5', '140'],
            'tenant/currencies' => ['KES', 'USD'],
            "companies/{$a->id('company')}/tax-codes?status=all" => ['VAT_STD', '12.5%'],
            'tax-categories?status=all' => ['Goods A', 'VAT_STD in Company A'],
            "companies/{$a->id('company')}/price-lists?status=all" => ['Retail A'],
            'uoms?status=all' => ['EA', 'BOX'],
            'item-categories?status=all' => ['Goods A', 'Goods A sub'],
            "companies/{$a->id('company')}/payment-methods?status=all" => ['M-Pesa', 'Cash KES'],
            "companies/{$a->id('company')}/departments?status=all" => ['A-1', 'Unit A renamed', 'Root A'],
            "companies/{$a->id('company')}/cost-centres?status=all" => ['A-1', 'Unit A renamed'],
            "companies/{$a->id('company')}/projects?status=all" => ['A-1', 'Unit A renamed'],
            // NOT-01, NOT-06: the owner's inbox and the delivery log.
            'notifications?status=all' => ['Isolation A from Owner A', 'Note: Stock count A'],
            'notification-deliveries' => ['Owner A', 'Manager A', 'manager-a@example.com'],
            // AUTO-01, AUTO-05: the rules and the run log.
            'automation-rules?status=all' => ['Rule A'],
            'automation-runs' => ['Rule A', 'Done'],

            // APR-04: the oversight list of approvals.
            'approvals?view=all&status=all' => ['Approve A', 'Waiting'],

            // Payments and fiscal: the till's payments, money received, the fiscal queue.
            "companies/{$a->id('company')}/payment-intents" => ['QJK3AMANUAL', 'Code entered'],
            "companies/{$a->id('company')}/payment-receipts" => ['QJK3ALOOSE1', 'KES 700.00'],
            "companies/{$a->id('company')}/fiscal-submissions" => ['Sale', 'Accepted'],
            // POS-12: sales and shifts of A's till.
            'pos/sales?status=all' => ['Outlet A', 'Owner A', 'KES 1,125.00'],
            'pos/shifts' => ['Outlet A', 'Owner A', 'KES 5,000.00'],
            // Inventory (INV-19, INV-20): its lists.
            'inventory/stock' => ['ITEM-A', 'Outlet A'],
            'inventory/movements' => ['ITEM-A', 'Opening stock'],
            'inventory/transfers' => ['Outlet A', 'Store A'],
            'inventory/adjustments' => ['Outlet A', 'Damaged'],
            'inventory/counts' => ['Store A', 'Cycle count'],
            'inventory/reorder-rules' => ['ITEM-A', 'Outlet A'],
            'inventory/valuation' => ['ITEM-A', 'Company A'],
            // Stores (STR-18): its lists (stock on hand is empty while Inventory holds the stores' stock).
            'stores/requisitions?status=all' => ['Store A', 'Unit A renamed'],
            'stores/issues' => ['Store A', 'Unit A renamed'],
            'stores/returns' => ['Store A', 'Unused'],
            'stores/ledger' => ['ITEM-A', 'Store A'],
            'stores/stock' => [],
            'stores/consumption?group=cost_centre' => ['ITEM-A', 'Unit A renamed'],
            // Manufacturing (MFG-27): its lists.
            'manufacturing/boms' => ['MFG-PRODUCT-A', 'MFG-MENU-A'],
            'manufacturing/orders' => ['MFG-PRODUCT-A', 'Outlet A'],
            'manufacturing/consumption' => ['ITEM-A'],
            'manufacturing/yield' => ['MFG-PRODUCT-A'],
            'manufacturing/backflushes' => ['Outlet A'],
            'manufacturing/recipe-costing' => ['MFG-MENU-A'],
            // HR (HR-35): its lists.
            'hr/employees' => ['Worker A', 'Peer A', 'Cashier A'],
            'hr/leave-requests' => ['Peer A', 'Annual A'],
            'hr/roster-shifts?from=2026-12-01&to=2026-12-07' => ['Worker A', 'Day A'],
            'hr/punches' => ['Worker A', 'Gate A'],
            'hr/timesheets' => ['Worker A'],
            'hr/disciplinary-cases' => ['Worker A'],
            'hr/appraisals' => ['Worker A', 'Review A'],
            // ACC-20: the chart and the journals of A's company.
            "companies/{$a->id('company')}/accounting/accounts?status=all" => ['6200', 'Rent'],
            "companies/{$a->id('company')}/accounting/journals?status=all" => ['Rent A'],
            "companies/{$a->id('company')}/accounting/documents?status=all" => ['Customer A'],
            "companies/{$a->id('company')}/accounting/payments" => ['Customer A', 'DEPA'],
            // Loyalty (LOY-07, LOY-18, LOY-20, LOY-23): promotions, credit accounts (and their ageing), gift cards, sales to review.
            'loyalty/promotions?status=all' => ['Ten off A'],
            'loyalty/accounts' => ['Customer A'],
            'loyalty/ageing' => ['Customer A'],
            'loyalty/gift-cards' => ['KES'],
            'loyalty/sales' => ['Customer A'],
            // Procurement (PRC-21): its lists.
            'procurement/requisitions?status=all' => ['Branch A', 'Restock A'],
            'procurement/rfqs?status=all' => ['Soap RFQ A'],
            'procurement/tenders?status=all' => ['Opened tender A', 'Awarded tender A'],
            'procurement/orders?status=all' => ['Supplier A'],
            'procurement/receipts?status=all' => ['Supplier A', 'Outlet A'],
            'procurement/invoices?status=all' => ['Supplier A', 'SINV-A'],
            'procurement/landed-costs?status=all' => ['Freight A'],
            // CRM (CRM-32): its lists.
            'crm/leads?status=all' => ['Lead A', 'Prospect A'],
            'crm/opportunities?status=all' => ['Deal A', 'Customer A'],
            'crm/quotes?status=all' => ['Customer A'],
            'crm/activities' => ['Call A', 'Hello A'],
            // Fixed assets (FA-26): the register.
            'fixed-assets/assets' => ['Van A', 'Truck A'],
            // Stage 7D closing review: every other list export (unlistedExports() keeps this complete).
            'credit-limit-changes' => ['Customer A', 'Season A'],
            'custom-fields' => ['Colour A', 'Manual A'],
            "custom-form-types/{$a->id('custom_form_type')}/records" => ['Branch A'],
            'imports' => ['customers-a.csv'],
            "inventory/items/{$a->id('item')}/movements" => ['Outlet A', 'Opening stock'],
            "price-lists/{$a->id('price_list')}/prices" => ['ITEM-A', 'BOX'],
            'workflows' => ['Company A'],
            'procurement/supplier-performance' => ['Supplier A'],
            'subscription/invoices' => ['KES'],
            'payroll/employees' => ['Worker A', 'Peer A'],
            'payroll/loans' => ['LN-A'],
            'payroll/runs' => ['Monthly A'],
            "payroll/runs/{$a->id('payroll_run')}/lines" => ['Worker A'],
            // E-commerce (ECOM-22): the orders list.
            'ecommerce/orders?status=all' => ['Shopper A'],
        ];
    }

    /** Every cell of an xlsx file, as one string. */
    private function xlsxText(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'isolation-xlsx-');
        file_put_contents($path, $content);
        $reader = new XlsxReader;
        $reader->open($path);
        $cells = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->cells as $cell) {
                    $cells[] = (string) $cell->getValue();
                }
            }
        }

        $reader->close();
        unlink($path);

        return implode("\n", $cells);
    }

    // ---- Global tables ----------------------------------------------------

    /**
     * personal_access_tokens and verification_challenges carry a tenant_id
     * but are deliberately global (read before the tenant is known; see
     * GlobalTables::TABLES). Authenticated code reaches them only
     * through the signed-in user's own rows: prove it with B's rows.
     */
    public function test_global_tables_are_reached_only_through_the_signed_in_users_own_rows(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;

        // Sessions: B's token id is neither listed nor revocable by A.
        $this->getJson('/api/v1/auth/sessions', $a->bearer())->assertOk()
            ->assertJsonMissing(['id' => $b->id('session')]);
        $this->deleteJson("/api/v1/auth/sessions/{$b->id('session')}", [], $a->bearer())->assertNotFound();
        $this->assertTrue(DB::table('personal_access_tokens')->where('id', $b->id('session'))->exists());

        // Challenges: B starts SMS two-factor; A cannot spend B's code.
        $this->postJson('/api/v1/me/two-factor/sms', [], $b->bearer())->assertOk();
        $code = $this->lastVerificationCode();
        $challenge = VerificationChallenge::where('tenant_id', $b->tenantId)
            ->where('purpose', VerificationChallenge::PURPOSE_TWO_FACTOR)->sole();

        $refused = $this->postJson('/api/v1/me/two-factor/sms/confirm', ['code' => $code], $a->bearer());
        $this->assertContains($refused->getStatusCode(), [403, 404, 422], 'tenant A confirmed with tenant B\'s two-factor code');
        $this->assertNull($challenge->fresh()->consumed_at, "tenant A consumed tenant B's challenge");

        // Control: B's own code still works.
        $this->postJson('/api/v1/me/two-factor/sms/confirm', ['code' => $code], $b->bearer())->assertOk();
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * Every public table with a tenant_id, read from the catalogue at run
     * time so a new table is covered automatically. The global tables are
     * left out here (they have no RLS by design) and are covered by
     * test_global_tables_are_reached_only_through_the_signed_in_users_own_rows.
     *
     * @return list<string>
     */
    private function tenantTables(): array
    {
        $tables = collect(DB::select("
            select c.table_name from information_schema.columns c
            join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
            where c.table_schema = 'public' and c.column_name = 'tenant_id' and t.table_type = 'BASE TABLE'
            order by c.table_name
        "))->pluck('table_name')->diff(GlobalTables::TABLES)->values()->all();

        $this->assertNotEmpty($tables);

        return $tables;
    }

    /**
     * LIST_ID_QUERIES the route reads, with B's ids (must show nothing of B
     * beyond the id echoed in its own pagination links) and with A's ids
     * (the control: the filter really runs). Routes that do not read the
     * parameter are not sent it: their pagination links would echo B's id.
     *
     * @return list<array<string, string>>
     */
    private function idQueries(RoutingRoute $route, TenantFixture $a, TenantFixture $b): array
    {
        $queries = [];
        $read = $this->queryParametersRead($route);

        foreach (array_intersect_key(self::LIST_ID_QUERIES, array_flip($read)) as $parameter => $key) {
            $queries[] = [$parameter => $b->id($key)];
            $queries[] = [$parameter => $a->id($key)];
        }

        return $queries;
    }

    /** @return list<RoutingRoute> */
    private function apiRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1/'))
            ->values()->all();
    }

    /** @return list<string> */
    private function methods(RoutingRoute $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD']));
    }

    /** ADR 013: the platform staff API (global data, `auth:platform`). */
    private function isPlatform(RoutingRoute $route): bool
    {
        return str_starts_with($route->uri(), 'api/v1/platform/') && ! $this->isPublic($route);
    }

    private function isPublic(RoutingRoute $route): bool
    {
        foreach ($this->methods($route) as $method) {
            if (isset(self::PUBLIC_ROUTES["{$method} {$route->uri()}"])) {
                return true;
            }
        }

        return false;
    }

    private function uriWith(RoutingRoute $route, TenantFixture $tenant, bool $platform = false): string
    {
        $uri = '/'.$route->uri();

        if ($platform) {
            // Platform ids are catalogue rows, never tenant rows: any UUID will do,
            // or the first allowed value of a constrained parameter (ADM-07 reports).
            foreach ($route->parameterNames() as $name) {
                $where = $route->wheres[$name] ?? '';
                $value = preg_match('/^[a-z0-9_-]+(\|[a-z0-9_-]+)*$/', $where) ? explode('|', $where)[0] : '00000000-0000-7000-8000-000000000000';
                $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $value, $uri);
            }

            return $uri;
        }

        foreach ($route->parameterNames() as $name) {
            if (isset(self::GLOBAL_PARAMETERS[$name])) {
                $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], self::GLOBAL_PARAMETERS[$name], $uri);

                continue;
            }

            $this->assertArrayHasKey($name, self::PARAMETERS, sprintf(
                'Route %s has the parameter {%s}, which the isolation suite cannot fill: add it to %s::PARAMETERS and give both tenants a row of that type in tests/Support/TwoTenants.php.',
                $route->uri(), $name, self::class,
            ));
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $tenant->id(self::PARAMETERS[$name]), $uri);
        }

        return $uri;
    }

    private function hasOnlyGlobalParameters(RoutingRoute $route): bool
    {
        return array_diff($route->parameterNames(), array_keys(self::GLOBAL_PARAMETERS)) === [];
    }

    /** TEN-05: device routes are called with the tenant's paired device, every other route as its Owner. */
    private function bearerFor(RoutingRoute $route, TenantFixture $tenant): array
    {
        return in_array(EnsureDeviceToken::class, $route->gatherMiddleware(), true) ? $tenant->bearer('device') : $tenant->bearer();
    }

    /** A body every mutating route would accept, pointing at more of B where a route takes ids. */
    private function hijackBody(TenantFixture $b): array
    {
        return [
            'name' => 'Hijacked by A',
            'code' => 'HIJACK',
            'type' => 'outlet',
            'country' => 'KE',
            'role_id' => $b->id('role'),
            'scope_type' => 'tenant',
            'permissions' => ['core.location.view'],
        ];
    }

    /** B's ids (every row of every tenant table, its tokens and challenges) and contacts. */
    private function identifiersOf(TenantFixture $tenant): array
    {
        return $this->identifiers[$tenant->tenantId] ??= $this->asTenant($tenant->tenantId, function () use ($tenant) {
            $ids = [$tenant->tenantId, ...array_values($tenant->ids), ...$tenant->contacts];

            foreach ($this->tenantTables() as $table) {
                if ($this->hasColumn($table, 'id')) {
                    array_push($ids, ...DB::table($table)->pluck('id')->map(fn ($id) => (string) $id));
                }
            }

            foreach (GlobalTables::TABLES as $table) {
                array_push($ids, ...DB::table($table)->where('tenant_id', $tenant->tenantId)->pluck('id')->map(fn ($id) => (string) $id));
            }

            return array_values(array_unique($ids));
        });
    }

    private function assertBodyHasNothingOf(TenantFixture $tenant, TestResponse $response, string $what): void
    {
        $body = $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();

        foreach ($this->identifiersOf($tenant) as $value) {
            $this->assertStringNotContainsString($value, $body, "{$what} ({$response->getStatusCode()}) exposes {$value} of tenant B");
        }
    }

    /**
     * A fingerprint of every row tenant B owns: each tenant table read in
     * B's context, plus B's rows of the global tables.
     *
     * @return array<string, string>
     */
    private function snapshot(string $tenantId): array
    {
        return $this->asTenant($tenantId, function () use ($tenantId) {
            $hashes = [];

            foreach ([...$this->tenantTables(), 'tenants'] as $table) {
                $hashes[$table] = DB::selectOne("select md5(coalesce(string_agg(t::text, '|' order by t::text), '')) as h from \"{$table}\" t")->h;
            }

            foreach (GlobalTables::TABLES as $table) {
                $hashes[$table] = DB::selectOne("select md5(coalesce(string_agg(t::text, '|' order by t::text), '')) as h from \"{$table}\" t where tenant_id = ?", [$tenantId])->h;
            }

            return $hashes;
        });
    }

    /** The Form Request class the route's action takes, if any. */
    private function formRequestOf(RoutingRoute $route): ?string
    {
        $uses = $route->getAction('uses');

        if (! is_string($uses)) {
            return null;
        }

        [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * The keys of the route's Form Request rules(), built as for a real
     * request by A's Owner on A's own resources (rules may read the route).
     *
     * @return list<string>
     */
    private function ruleKeys(RoutingRoute $route, string $method, TenantFixture $a): array
    {
        $class = $this->formRequestOf($route);

        if ($class === null) {
            return [];
        }

        return $this->asTenant($a->tenantId, function () use ($class, $route, $method, $a) {
            $owner = User::findOrFail($a->id('user'));
            $request = $class::create($this->uriWith($route, $a), $method);
            $request->setContainer(app())->setUserResolver(fn () => $owner);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            app('router')->substituteBindings($route);
            app('router')->substituteImplicitBindings($route);

            return array_keys(app()->call([$request, 'rules']));
        });
    }

    /**
     * Rule keys naming a row by id (`role_id`, `assignments.*.scope_id`);
     * fails for an `_id` field the suite cannot fill.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function idFields(array $keys): array
    {
        $fields = [];

        foreach ($keys as $key) {
            $leaf = Str::afterLast($key, '.');

            if (! str_ends_with($leaf, '_id') || isset(self::NOT_REFERENCES[$leaf])) {
                continue;
            }

            $this->assertArrayHasKey($leaf, self::REFERENCE_FIELDS, sprintf(
                'The body field [%s] looks like an id the isolation suite cannot fill: add it to %s::REFERENCE_FIELDS (or NOT_REFERENCES with a reason).',
                $key, self::class,
            ));
            $fields[] = $key;
        }

        return $fields;
    }

    /**
     * $base with one id field at a time set to B's id (scope_id once per
     * scope type), then all of them at once.
     *
     * @param  list<string>  $fields
     * @param  array<string, string>  $references  this route's REFERENCE_FIELDS overrides
     * @return array<string, array>
     */
    private function hijackVariants(array $base, array $fields, TenantFixture $b, array $references = []): array
    {
        $references = [...self::REFERENCE_FIELDS, ...$references];

        $variants = [];
        $all = $base;

        foreach ($fields as $field) {
            $path = str_replace('*', '0', $field);
            $leaf = Str::afterLast($field, '.');

            if ($leaf === 'scope_id') {
                $typePath = Str::beforeLast($path, 'scope_id').'scope_type';

                foreach (self::SCOPE_IDS as $type => $idType) {
                    $body = $base;
                    data_set($body, $typePath, $type);
                    data_set($body, $path, $b->id($idType));
                    $variants["{$field} = {$type} {$b->id($idType)}"] = $body;
                }

                data_set($all, $path, $b->id(self::SCOPE_IDS[data_get($base, $typePath)]));

                continue;
            }

            $body = $base;
            data_set($body, $path, $b->id($references[$leaf]));
            $variants["{$field} = {$b->id($references[$leaf])}"] = $body;
            data_set($all, $path, $b->id($references[$leaf]));
        }

        $variants['every id field'] = $all;

        return $variants;
    }

    /** A valid body for "METHOD uri" naming $tenant's own rows; null when the suite has none. */
    private function bodyFor(string $key, TenantFixture $tenant): ?array
    {
        $journal = ['date' => '2026-10-05', 'lines' => [
            ['account_id' => $tenant->id('acc_account'), 'debit' => '10.00', 'party_id' => $tenant->id('party'), 'branch_id' => $tenant->id('branch'),
                'cost_centre_id' => $tenant->id('cost_centre'), 'department_id' => $tenant->id('department'), 'project_id' => $tenant->id('project')],
            ['account_id' => $tenant->id('acc_free_asset'), 'credit' => '10.00'],
        ]];
        $documentLines = [['description' => 'Hijack', 'item_id' => $tenant->id('item'), 'account_id' => $tenant->id('acc_account'), 'qty' => '1', 'unit_price' => '10.00',
            'tax_code_id' => $tenant->id('tax_code'), 'cost_centre_id' => $tenant->id('cost_centre'), 'department_id' => $tenant->id('department'), 'project_id' => $tenant->id('project')]];
        $assignment = ['role_id' => $tenant->id('role'), 'scope_type' => 'location', 'scope_id' => $tenant->id('location')];
        $procurementLines = [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1', 'cost_centre_id' => $tenant->id('cost_centre'), 'project_id' => $tenant->id('project')]];
        // CRM-18: a quote line of the shared item's box (its list price), tax from core.
        $crmLines = [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1', 'tax_code_id' => $tenant->id('tax_code')]];
        $procurementOrderLines = [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1', 'unit_price_minor' => '118000', 'tax_code_id' => $tenant->id('tax_code'),
            'cost_centre_id' => $tenant->id('cost_centre'), 'project_id' => $tenant->id('project'), 'requisition_line_id' => null]];

        return match ($key) {
            'POST api/v1/invitations' => ['name' => 'Invitee', 'email' => 'invitee-hijack@example.com', 'assignments' => [$assignment]],
            'POST api/v1/users/{user}/assignments' => $assignment,
            // AUTH-11: linking one's own account; JIT provisioning's default role (A's custom role) at A's company.
            'POST api/v1/me/sso/link' => ['connection_id' => $tenant->id('sso_live_connection'), 'origin' => 'https://app.example.test'],
            'PUT api/v1/sso/settings' => ['jit_role_id' => $tenant->id('role'), 'jit_scope_type' => 'company', 'jit_scope_id' => $tenant->id('company')],
            // API-02: a key with the custom role at the outlet.
            'POST api/v1/api-keys' => ['name' => 'Hijack key', ...$assignment, 'scopes' => ['core.location.read']],
            // MKT-02: consent to the shared app (again) as the fixture role at A's location.
            'POST api/v1/oauth/authorize' => ['client_id' => TwoTenants::MARKETPLACE_CLIENT, 'redirect_uri' => TwoTenants::MARKETPLACE_REDIRECT, 'response_type' => 'code',
                'code_challenge' => TwoTenants::PKCE_CHALLENGE, 'code_challenge_method' => 'S256', 'decision' => 'approve', ...$assignment],
            'POST api/v1/apps/installed/{app_installation}/consent' => $assignment,
            // LOY-01: the programme's gift card item (A's item is shared, TEN-08).
            'PUT api/v1/companies/{company}/loyalty-programme' => ['gift_card_item_id' => $tenant->id('gift_card_item')],
            // LOY-13: a preview of a draft bundle for the customer at the branch.
            'POST api/v1/loyalty/promotions/preview' => [
                'company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'), 'party_id' => $tenant->id('customer'), 'currency' => 'KES',
                'promotion' => ['type' => 'bundle', 'currency' => 'KES', 'amount_minor' => '1000', 'components' => [['item_id' => $tenant->id('item'), 'qty' => 2]]],
                'lines' => [['item_id' => $tenant->id('item'), 'qty' => '2', 'unit_price_minor' => '56250']],
            ],
            'PATCH api/v1/loyalty/promotions/{loyalty_promotion}' => ['components' => [['item_id' => $tenant->id('item'), 'qty' => 2]]],
            'POST api/v1/loyalty/customers/{party}/points/adjust' => ['company_id' => $tenant->id('company'), 'points' => '5', 'reason' => 'Hijack check'],
            'POST api/v1/loyalty/accounts' => ['party_id' => $tenant->id('loyalty_customer'), 'company_id' => $tenant->id('company')],
            'POST api/v1/loyalty/gift-cards' => ['company_id' => $tenant->id('company'), 'party_id' => $tenant->id('customer'), 'kind' => 'voucher', 'currency' => 'KES', 'amount_minor' => '1000'],
            // LOY-08: a bundle of the company's (shared) item.
            'POST api/v1/loyalty/promotions' => [
                'company_id' => $tenant->id('company'), 'name' => 'Hijack bundle', 'type' => 'bundle', 'currency' => 'KES', 'amount_minor' => '1000',
                'components' => [['item_id' => $tenant->id('item'), 'qty' => 2]],
            ],
            'POST api/v1/subscription/invoices/{billing_invoice}/pay' => ['payment_method_id' => $tenant->id('billing_payment_method')],
            // MD-03: a shared category (items are shared, TEN-08) mapping the company's tax code.
            'POST api/v1/tax-categories' => [
                'name' => 'Hijack check',
                'codes' => [['company_id' => $tenant->id('company'), 'tax_code_id' => $tenant->id('tax_code')]],
            ],
            'PATCH api/v1/tax-categories/{tax_category}' => [
                'codes' => [['company_id' => $tenant->id('company'), 'tax_code_id' => $tenant->id('tax_code')]],
            ],
            // MD-01, TEN-08: suppliers are kept per company in TwoTenants.
            'POST api/v1/parties' => [
                'kind' => 'organisation', 'name' => 'Hijack supplier', 'roles' => ['supplier'],
                'company_id' => $tenant->id('company'), 'price_list_id' => $tenant->id('price_list'),
            ],
            'PATCH api/v1/parties/{party}' => ['company_id' => $tenant->id('company'), 'price_list_id' => $tenant->id('price_list')],
            // MD-02: a shared item in A's category and tax category, with a box and a barcode for it.
            'POST api/v1/items' => [
                'code' => 'HIJACK-ITEM', 'name' => 'Hijack item', 'type' => 'stock', 'base_uom_id' => $tenant->id('uom'),
                'category_id' => $tenant->id('item_category'), 'tax_category_id' => $tenant->id('tax_category'),
                'uoms' => [['uom_id' => $tenant->id('uom_box'), 'factor' => '6']],
                'barcodes' => [['barcode' => 'HIJACK1', 'uom_id' => $tenant->id('uom_box')]],
            ],
            'PATCH api/v1/items/{item}' => [
                'base_uom_id' => $tenant->id('uom'), 'category_id' => $tenant->id('item_category'), 'tax_category_id' => $tenant->id('tax_category'),
                'uoms' => [['uom_id' => $tenant->id('uom_box'), 'factor' => '12']],
                'barcodes' => [['barcode' => '6161000000001'], ['barcode' => '6161000000018', 'uom_id' => $tenant->id('uom_box')]],
            ],
            // MD-03 follow-up: the item's box in the company's default list, one price or a batch.
            'POST api/v1/price-lists/{price_list}/prices' => ['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'amount_minor' => '130000', 'currency' => 'KES'],
            'POST api/v1/price-lists/{price_list}/prices/bulk' => ['prices' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'amount_minor' => '131000', 'currency' => 'KES']]],
            'POST api/v1/item-categories' => ['name' => 'Hijack category', 'parent_id' => $tenant->id('item_category_parent')],
            'PATCH api/v1/item-categories/{item_category}' => ['parent_id' => $tenant->id('item_category_parent')],
            // MD-05: a child of the company's parent row, owned by the Owner.
            'POST api/v1/companies/{company}/departments' => ['code' => 'HIJACK-D', 'name' => 'Hijack', 'parent_id' => $tenant->id('department_parent'), 'owner_user_id' => $tenant->id('user')],
            'PATCH api/v1/departments/{department}' => ['parent_id' => $tenant->id('department_parent'), 'owner_user_id' => $tenant->id('user')],
            'POST api/v1/companies/{company}/cost-centres' => ['code' => 'HIJACK-C', 'name' => 'Hijack', 'parent_id' => $tenant->id('cost_centre_parent'), 'owner_user_id' => $tenant->id('user')],
            'PATCH api/v1/cost-centres/{cost_centre}' => ['parent_id' => $tenant->id('cost_centre_parent'), 'owner_user_id' => $tenant->id('user')],
            'POST api/v1/companies/{company}/projects' => ['code' => 'HIJACK-P', 'name' => 'Hijack', 'parent_id' => $tenant->id('project_parent'), 'owner_user_id' => $tenant->id('user')],
            'PATCH api/v1/projects/{project}' => ['parent_id' => $tenant->id('project_parent'), 'owner_user_id' => $tenant->id('user')],
            // WF-02: a flow for the sign-up company (TwoTenants made the company's), and a copy of the company's there.
            'POST api/v1/workflows' => ['document_type' => TestRequestType::KEY, 'company_id' => $tenant->id('sign_up_company')],
            'POST api/v1/workflows/{workflow}/copy' => ['company_id' => $tenant->id('sign_up_company'), 'from' => 'published'],
            // AUTO-01..AUTO-04, AUTO-07: a rule for A's company, the same rule tested unsaved, a test
            // against A's document, and a template used for A's company.
            'POST api/v1/automation-rules' => [...self::automationRule(), 'company_id' => $tenant->id('company')],
            'PATCH api/v1/automation-rules/{automation_rule}' => ['company_id' => $tenant->id('company')],
            // HOOK-01: a webhook filtered to a company (or a branch).
            'POST api/v1/webhooks' => ['url' => 'https://hooks.example.com/hijack', 'events' => ['sale.completed.v1'], 'company_id' => $tenant->id('company')],
            // NFR-08: a data request and a consent record about the shared customer.
            'POST api/v1/privacy/requests' => ['subject_type' => 'customer', 'subject_id' => $tenant->id('customer'), 'kind' => 'access'],
            'POST api/v1/privacy/consents' => ['subject_type' => 'customer', 'subject_id' => $tenant->id('customer'), 'purpose' => 'marketing', 'channel' => 'sms', 'granted' => true],
            'PATCH api/v1/webhooks/{webhook}' => ['branch_id' => $tenant->id('branch')],
            'POST api/v1/automation-rules/test' => [...self::automationRule(), 'company_id' => $tenant->id('company')],
            'POST api/v1/automation-rules/{automation_rule}/test' => ['document_id' => $tenant->id('document')],
            'POST api/v1/automation-templates/use' => [
                'template' => 'core.alert_below_level', 'document_type' => TestRequestType::KEY, 'company_id' => $tenant->id('company'),
                'params' => ['field' => 'total', 'value' => ['amount_minor' => '100', 'currency' => 'KES']],
            ],

            // APR-06: the manager's pending approval goes to the owner; the owner delegates to the manager.
            'POST api/v1/approvals/{approval}/reassign' => ['from_user_id' => $tenant->id('manager'), 'to_user_id' => $tenant->id('user')],
            'POST api/v1/me/delegations' => ['to_user_id' => $tenant->id('manager'), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDay()->toDateString()],
            // WF-01: a credit limit change for the company's supplier.
            // CF-04: a record of the company's branch (the type is the caller's; the place ids are swapped).
            'POST api/v1/custom-form-types/{custom_form_type}/records' => ['company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'), 'location_id' => $tenant->id('location')],
            'POST api/v1/credit-limit-changes' => [
                'party_id' => $tenant->id('party'), 'company_id' => $tenant->id('company'),
                'requested_limit' => ['amount_minor' => '100000', 'currency' => 'KES'], 'reason' => 'Hijack check',
            ],
            // TEN-08: customers move to per company, every shared one to A's company.
            'PUT api/v1/master-data/settings' => [
                'data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $tenant->id('company'),
            ],
            // NUM-01: a branch's receipt format.
            'PUT api/v1/numbering/formats' => [
                'document_type' => 'pos.receipt', 'company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'),
                'pattern' => 'R-{BRANCH}-{000001}', 'reset' => 'never',
            ],
            // POS-09 (device token): a sale on the till's open shift with a manager's
            // override on its line; a shift opened and closed in one upload; a pay-in
            // with an override; a void of the spare sale; the second unit of the
            // refunded sale given back.
            'POST api/v1/pos/sales' => ['sales' => [[
                ...($sale = TwoTenants::posSale(fn () => (string) Str::uuid7(), $tenant->id('pos_shift'), $tenant->id('user'), 50,
                    str_replace('{000001}', '000050', $tenant->id('pos_receipt_pattern')), $tenant->id('item'), $tenant->id('uom'),
                    $tenant->id('price_list'), $tenant->id('payment_method'), $tenant->id('tax_code'))),
                'customer_id' => $tenant->id('customer'),
                'lines' => [[...$sale['lines'][0], 'override' => ['manager_user_id' => $tenant->id('manager'), 'cashier_user_id' => $tenant->id('user')]]],
            ]]],
            'POST api/v1/pos/shifts' => ['shifts' => [[
                'id' => (string) Str::uuid7(), 'opened_by_id' => $tenant->id('user'), 'opened_at' => now()->subMinutes(30)->toIso8601String(),
                'opening_float' => [['currency' => 'KES', 'amount_minor' => '1000']],
                'closing' => ['closed_by_id' => $tenant->id('user'), 'closed_at' => now()->toIso8601String(), 'counted' => [['currency' => 'KES', 'amount_minor' => '1000']]],
            ]]],
            'POST api/v1/pos/cash-movements' => ['movements' => [[
                'id' => (string) Str::uuid7(), 'shift_id' => $tenant->id('pos_shift'), 'user_id' => $tenant->id('user'), 'kind' => 'pay_out',
                'currency' => 'KES', 'amount_minor' => '500', 'reason' => 'Hijack check', 'occurred_at' => now()->toIso8601String(),
            ]]],
            'POST api/v1/pos/voids' => ['voids' => [[
                'id' => (string) Str::uuid7(), 'sale_id' => $tenant->id('pos_sale_spare'), 'voided_by_id' => $tenant->id('user'),
                'voided_at' => now()->toIso8601String(), 'reason' => 'Hijack check',
            ]]],
            'POST api/v1/pos/refunds' => ['refunds' => [[
                ...TwoTenants::posRefund(fn () => (string) Str::uuid7(), ['id' => $tenant->id('pos_sale'), 'lines' => [['id' => $tenant->id('pos_sale_line')]]],
                    $tenant->id('pos_shift'), $tenant->id('user'), 2, str_replace('{000001}', '000002', $tenant->id('pos_refund_pattern')), $tenant->id('payment_method')),
            ]]],
            // AUTH-06..AUTH-08, from A's device: the owner signs in, a report of no
            // wrong PINs, and the owner approving a void for the manager.
            'POST api/v1/pos/pin/verify' => ['user_id' => $tenant->id('user'), 'pin' => TwoTenants::PIN],
            'POST api/v1/pos/pin/attempts' => ['reports' => [['user_id' => $tenant->id('user'), 'failed_attempts' => 0, 'locked' => false]]],
            'POST api/v1/pos/override' => [
                'manager_user_id' => $tenant->id('user'), 'pin' => TwoTenants::PIN, 'permission' => 'pos.sale.void', 'cashier_user_id' => $tenant->id('manager'),
                'reference' => 'sale-hijack-check',
            ],
            // The owner picks a new PIN at the till (the last device route the suite calls with it).
            'POST api/v1/pos/pin/change' => ['user_id' => $tenant->id('user'), 'pin' => TwoTenants::PIN, 'new_pin' => '739104'],
            // Payments (device token): a manual M-Pesa payment by the owner at the till.
            'POST api/v1/payments/intents' => [
                'payment_method_id' => $tenant->id('payment_method'), 'mode' => 'manual', 'amount_minor' => '10000', 'currency' => 'KES',
                'receipt' => 'QJK3HIJACK'.strtoupper(Str::random(4)), 'reference_type' => 'pos.sale', 'reference' => (string) Str::uuid7(), 'user_id' => $tenant->id('user'),
            ],
            // LAY-06: a layout for the location (saved again on every call: one draft), and a copy of the company's there.
            'POST api/v1/config/{kind}' => ['scope_type' => 'location', 'scope_id' => $tenant->id('location'), 'payload' => ['columns' => [['id' => 'name']]]],
            'POST api/v1/config/{kind}/{config_document}/copy' => ['scope_type' => 'location', 'scope_id' => $tenant->id('location'), 'from' => 'published', 'replace' => true],
            // TPL-01: a template preview for the company (TPL-03 reads the company's country).
            'POST api/v1/templates/preview' => ['type' => 'pos.receipt', 'payload' => DefaultTemplates::for('pos.receipt'), 'scope_type' => 'company', 'scope_id' => $tenant->id('company')],
            // Inventory: opening stock of the item's box; a reorder level; the item as a
            // variant of the batch item; a transfer to the store, an adjustment and a
            // cycle count of the item's category at the outlet; labels priced from the list.
            'POST api/v1/inventory/opening-stock' => ['item_id' => $tenant->id('item'), 'location_id' => $tenant->id('location'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1', 'unit_cost' => '120'],
            'PUT api/v1/inventory/reorder-rules' => ['item_id' => $tenant->id('item'), 'location_id' => $tenant->id('location'), 'reorder_level' => '6', 'reorder_qty' => '12'],
            'PUT api/v1/inventory/items/{item}/variant' => ['parent_item_id' => $tenant->id('inventory_item'), 'attributes' => ['size' => 'Hijack']],
            'POST api/v1/inventory/transfers' => [
                'from_location_id' => $tenant->id('location'), 'to_location_id' => $tenant->id('inventory_location'),
                'lines' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1', 'batch_id' => $tenant->id('inventory_batch')]],
            ],
            'POST api/v1/inventory/adjustments' => [
                'location_id' => $tenant->id('location'), 'reason' => 'found',
                'lines' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1', 'batch_id' => $tenant->id('inventory_batch')]],
            ],
            'POST api/v1/inventory/counts' => ['location_id' => $tenant->id('location'), 'kind' => 'cycle', 'category_id' => $tenant->id('item_category')],
            // Stores (STR-02, STR-04, STR-06, STR-07).
            'POST api/v1/stores/requisitions' => [
                'location_id' => $tenant->id('inventory_location'), 'department_id' => $tenant->id('department'), 'cost_centre_id' => $tenant->id('cost_centre'),
                'lines' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1']],
            ],
            'POST api/v1/stores/requisitions/{stores_requisition}/issues' => ['lines' => [['requisition_line_id' => $tenant->id('stores_requisition_line'), 'qty' => '1']]],
            'POST api/v1/stores/issues/{stores_issue}/returns' => ['reason' => 'unused', 'lines' => [['issue_line_id' => $tenant->id('stores_issue_line'), 'qty' => '1']]],
            'POST api/v1/stores/stock-entries' => [
                'location_id' => $tenant->id('inventory_location'), 'kind' => 'opening',
                'lines' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1']],
            ],
            // Manufacturing (MFG-02 to MFG-13): a BOM of the serial item from the batch item, the draft's lines,
            // a new version, an order of the product, its update, issue, return and report.
            'POST api/v1/manufacturing/boms' => ['company_id' => $tenant->id('company'), 'item_id' => $tenant->id('inventory_serial_item'), 'kind' => 'production', 'version' => [
                'output_qty' => '1', 'output_uom_id' => $tenant->id('uom'), 'lines' => [['item_id' => $tenant->id('inventory_item'), 'uom_id' => $tenant->id('uom'), 'qty' => '1']],
            ]],
            'POST api/v1/manufacturing/boms/{mfg_bom}/versions' => ['copy_from_id' => $tenant->id('mfg_bom_version_published')],
            'PUT api/v1/manufacturing/bom-versions/{mfg_bom_version}' => ['output_qty' => '1', 'output_uom_id' => $tenant->id('uom'), 'lines' => [
                ['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom'), 'qty' => '1'],
            ]],
            'POST api/v1/manufacturing/orders' => ['location_id' => $tenant->id('location'), 'item_id' => $tenant->id('mfg_product'), 'bom_version_id' => $tenant->id('mfg_bom_version_published'),
                'uom_id' => $tenant->id('uom'), 'qty' => '1'],
            'PUT api/v1/manufacturing/orders/{mfg_order}' => ['uom_id' => $tenant->id('uom'), 'qty' => '4'],
            'POST api/v1/manufacturing/orders/{mfg_order}/issues' => ['lines' => [['material_id' => $tenant->id('mfg_material'), 'uom_id' => $tenant->id('uom'), 'qty' => '1']]],
            'POST api/v1/manufacturing/orders/{mfg_order}/returns' => ['issue_id' => $tenant->id('mfg_issue'), 'reason' => 'unused', 'lines' => [['issue_line_id' => $tenant->id('mfg_issue_line'), 'qty' => '1']]],
            'POST api/v1/manufacturing/revalue' => ['kind' => 'issue', 'document_id' => $tenant->id('mfg_issue')],
            'POST api/v1/manufacturing/orders/{mfg_order}/reports' => ['qty_good' => '1', 'by_products' => [['output_id' => $tenant->id('mfg_output'), 'qty' => '1']]],
            // HR (docs/modules/hr.md): bodies naming A's own employees, places, dimensions, types, shifts and templates.
            'POST api/v1/hr/employees' => [
                'company_id' => $tenant->id('company'), 'first_name' => 'Hijack', 'last_name' => 'Check', 'hire_date' => '2026-02-01', 'branch_id' => $tenant->id('branch'),
                'location_id' => $tenant->id('location'), 'department_id' => $tenant->id('department'), 'position_id' => $tenant->id('hr_position'),
                'reports_to_id' => $tenant->id('hr_employee'), 'user_id' => $tenant->id('manager'),
            ],
            'PATCH api/v1/hr/employees/{hr_employee}' => [
                'branch_id' => $tenant->id('branch'), 'location_id' => $tenant->id('location'), 'department_id' => $tenant->id('department'),
                'position_id' => $tenant->id('hr_position'), 'user_id' => $tenant->id('user'),
            ],
            'POST api/v1/hr/employees/{hr_employee}/contracts' => [
                'type' => 'fixed_term', 'start_date' => '2025-01-06', 'end_date' => '2025-06-30', 'pay_basis' => 'monthly', 'pay_amount' => '1000', 'currency' => 'KES',
                'position_id' => $tenant->id('hr_position'), 'department_id' => $tenant->id('department'),
            ],
            'PATCH api/v1/hr/contracts/{hr_contract}' => ['position_id' => $tenant->id('hr_position'), 'department_id' => $tenant->id('department')],
            'POST api/v1/hr/employees/{hr_employee}/checklists' => ['template_id' => $tenant->id('hr_checklist_template')],
            'POST api/v1/hr/positions' => ['company_id' => $tenant->id('company'), 'code' => 'POS-HIJACK', 'title' => 'Hijack', 'department_id' => $tenant->id('department')],
            'PATCH api/v1/hr/positions/{hr_position}' => ['department_id' => $tenant->id('department')],
            'POST api/v1/hr/leave-types' => ['company_id' => $tenant->id('company'), 'code' => 'LT-HIJACK', 'name' => 'Hijack', 'accrual' => 'none', 'count_basis' => 'calendar_days'],
            'POST api/v1/hr/leave-types/apply-pack' => ['company_id' => $tenant->id('company')],
            'POST api/v1/hr/leave-adjustments' => ['employee_id' => $tenant->id('hr_employee'), 'leave_type_id' => $tenant->id('hr_leave_type'), 'days' => '1', 'reason' => 'Check'],
            'POST api/v1/hr/leave-accruals/run' => ['company_id' => $tenant->id('company')],
            'POST api/v1/hr/leave-requests' => ['employee_id' => $tenant->id('hr_employee'), 'leave_type_id' => $tenant->id('hr_leave_type'), 'start_date' => '2026-12-21', 'end_date' => '2026-12-22'],
            'POST api/v1/hr/shifts' => ['company_id' => $tenant->id('company'), 'code' => 'SH-HIJACK', 'name' => 'Hijack', 'start_time' => '09:00', 'end_time' => '17:00'],
            'POST api/v1/hr/rotations' => ['company_id' => $tenant->id('company'), 'name' => 'Hijack', 'days' => [$tenant->id('hr_shift')]],
            'POST api/v1/hr/rosters' => [
                'company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'), 'department_id' => $tenant->id('department'), 'name' => 'Hijack',
                'rotation_id' => $tenant->id('hr_rotation'), 'anchor_date' => '2026-12-07', 'members' => [['employee_id' => $tenant->id('hr_employee')]],
            ],
            'PATCH api/v1/hr/rosters/{hr_roster}' => ['branch_id' => $tenant->id('branch'), 'department_id' => $tenant->id('department'), 'rotation_id' => $tenant->id('hr_rotation')],
            'PUT api/v1/hr/rosters/{hr_roster}/members' => ['members' => [['employee_id' => $tenant->id('hr_employee'), 'offset_days' => 0], ['employee_id' => $tenant->id('hr_employee_peer'), 'offset_days' => 1]]],
            'POST api/v1/hr/roster-shifts' => ['employee_id' => $tenant->id('hr_employee'), 'date' => '2027-01-04', 'shift_id' => $tenant->id('hr_shift')],
            'PATCH api/v1/hr/roster-shifts/{hr_roster_shift}' => ['shift_id' => $tenant->id('hr_shift')],
            'POST api/v1/hr/shift-swaps' => [
                'employee_id' => $tenant->id('hr_employee'), 'roster_shift_id' => $tenant->id('hr_roster_shift'),
                'peer_employee_id' => $tenant->id('hr_employee_peer'), 'peer_roster_shift_id' => $tenant->id('hr_roster_shift_peer'),
            ],
            'POST api/v1/hr/time-rules' => ['company_id' => $tenant->id('company'), 'effective_from' => '2027-01-01', 'effective_to' => '2027-12-31'],
            'POST api/v1/hr/punches' => ['employee_id' => $tenant->id('hr_employee'), 'kind' => 'out', 'occurred_at' => '2026-10-05T17:00:00+03:00', 'note' => 'Check'],
            'POST api/v1/hr/timesheets/recompute' => ['company_id' => $tenant->id('company'), 'from' => '2026-10-01', 'to' => '2026-10-07'],
            'POST api/v1/hr/attendance-periods' => ['company_id' => $tenant->id('company'), 'start_date' => '2026-03-01', 'end_date' => '2026-03-31'],
            'POST api/v1/hr/attendance-devices' => ['location_id' => $tenant->id('location'), 'name' => 'Hijack'],
            'POST api/v1/hr/till/punches' => ['punches' => [['id' => (string) Str::uuid7(), 'employee_id' => $tenant->id('hr_employee'), 'kind' => 'in', 'occurred_at' => '2026-10-06T08:00:00+03:00']]],
            'POST api/v1/hr/till/clock-attempts' => ['reports' => [['employee_id' => $tenant->id('hr_employee'), 'failed_attempts' => 1, 'locked' => false]]],
            'POST api/v1/hr/checklist-templates' => ['company_id' => $tenant->id('company'), 'kind' => 'offboarding', 'name' => 'Hijack', 'items' => [['title' => 'Return keys']]],
            'POST api/v1/hr/disciplinary-cases' => ['employee_id' => $tenant->id('hr_employee'), 'category' => 'other', 'incident_date' => '2026-10-02', 'description' => 'Check'],
            'POST api/v1/hr/appraisal-cycles' => ['company_id' => $tenant->id('company'), 'name' => 'Hijack', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'],
            'PATCH api/v1/hr/appraisals/{hr_appraisal}' => ['reviewer_id' => $tenant->id('manager')],
            // HR-31: the Owner is linked to A's employee.
            'POST api/v1/hr/me/leave-requests' => ['leave_type_id' => $tenant->id('hr_leave_type'), 'start_date' => '2027-01-11', 'end_date' => '2027-01-12'],
            'POST api/v1/hr/me/swaps' => ['roster_shift_id' => $tenant->id('hr_roster_shift'), 'peer_employee_id' => $tenant->id('hr_employee_peer'), 'peer_roster_shift_id' => $tenant->id('hr_roster_shift_peer')],
            // Fixed assets (docs/modules/fixed-assets.md): bodies naming A's own places, categories, people, parties, schedules and assets.
            'POST api/v1/fixed-assets/categories' => ['company_id' => $tenant->id('company'), 'code' => 'HIJACK', 'name' => 'Hijack', 'method' => 'none'],
            'POST api/v1/fixed-assets/item-categories' => ['company_id' => $tenant->id('company'), 'item_id' => $tenant->id('fa_item_spare'), 'category_id' => $tenant->id('fa_category')],
            'POST api/v1/fixed-assets/assets' => [
                'location_id' => $tenant->id('location'), 'name' => 'Hijack', 'category_id' => $tenant->id('fa_category'), 'custodian_user_id' => $tenant->id('user'),
                'cost_centre_id' => $tenant->id('cost_centre'), 'department_id' => $tenant->id('department'), 'supplier_id' => $tenant->id('party'),
            ],
            'PATCH api/v1/fixed-assets/assets/{fa_asset}' => [
                'location_id' => $tenant->id('location'), 'category_id' => $tenant->id('fa_category'), 'custodian_user_id' => $tenant->id('user'),
                'cost_centre_id' => $tenant->id('cost_centre'), 'department_id' => $tenant->id('department'), 'supplier_id' => $tenant->id('party'),
            ],
            'POST api/v1/fixed-assets/assets/{fa_asset}/transfers' => [
                'to_location_id' => $tenant->id('location'), 'to_custodian_user_id' => $tenant->id('manager'), 'to_cost_centre_id' => $tenant->id('cost_centre'),
                'transfer_date' => '2026-03-01', 'reason' => 'Check',
            ],
            'POST api/v1/fixed-assets/assets/{fa_asset}/disposals' => ['kind' => 'sale', 'disposal_date' => '2026-03-01', 'proceeds_minor' => 100, 'party_id' => $tenant->id('party'), 'reason' => 'Check'],
            'POST api/v1/fixed-assets/assets/{fa_asset}/work-orders' => ['title' => 'Check', 'schedule_id' => $tenant->id('fa_schedule'), 'vendor_id' => $tenant->id('party')],
            'PATCH api/v1/fixed-assets/work-orders/{fa_work_order}' => ['schedule_id' => $tenant->id('fa_schedule'), 'vendor_id' => $tenant->id('party')],
            'POST api/v1/fixed-assets/runs' => ['company_id' => $tenant->id('company'), 'period' => '2026-02'],
            'POST api/v1/fixed-assets/labels' => ['labels' => [['asset_id' => $tenant->id('fa_asset'), 'copies' => 1]]],
            // Payroll (docs/modules/payroll.md): bodies naming A's own company, pay group, items and employees.
            'POST api/v1/payroll/pay-groups' => ['company_id' => $tenant->id('company'), 'code' => 'PG-HIJACK', 'name' => 'Hijack', 'frequency' => 'monthly'],
            'POST api/v1/payroll/items' => ['company_id' => $tenant->id('company'), 'code' => 'IT-HIJACK', 'name' => 'Hijack', 'kind' => 'earning'],
            'POST api/v1/payroll/employees/sync' => ['company_id' => $tenant->id('company')],
            'PATCH api/v1/payroll/employees/{payroll_employee}' => ['pay_group_id' => $tenant->id('payroll_pay_group')],
            'POST api/v1/payroll/employees/{payroll_employee}/items' => ['item_id' => $tenant->id('payroll_item'), 'amount_minor' => '100', 'currency' => 'KES', 'recurrence' => 'once', 'period_date' => '2027-02-10'],
            'POST api/v1/payroll/loans' => ['payroll_employee_id' => $tenant->id('payroll_employee'), 'kind' => 'advance', 'principal_minor' => '1000', 'instalment_minor' => '1000', 'currency' => 'KES', 'disbursed_on' => '2027-01-02'],
            'POST api/v1/payroll/runs' => ['pay_group_id' => $tenant->id('payroll_pay_group'), 'period_date' => '2027-03-15'],
            'POST api/v1/payroll/returns' => ['company_id' => $tenant->id('company'), 'type' => 'paye', 'period' => '2026-09'],
            'POST api/v1/inventory/labels' => [
                'labels' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'batch_id' => $tenant->id('inventory_batch'), 'copies' => 1]],
                'price_list_id' => $tenant->id('price_list'),
            ],
            // Payments: the money received matched to the till's manual payment.
            'POST api/v1/payment-receipts/{payment_receipt}/match' => ['payment_intent_id' => $tenant->id('payment_intent')],
            // Accounting (docs/modules/accounting.md): bodies naming A's own accounts, dimensions, parties and documents.
            'POST api/v1/companies/{company}/accounting/accounts' => ['code' => 'HJ-1', 'name' => 'Hijack', 'type' => 'expense', 'subtype' => 'expense', 'parent_id' => $tenant->id('acc_header')],
            'PATCH api/v1/accounting/accounts/{acc_account}' => ['parent_id' => $tenant->id('acc_header')],
            'PUT api/v1/companies/{company}/accounting/settings' => ['roles' => [['role' => 'suspense', 'account_id' => $tenant->id('acc_free_asset')]]],
            'POST api/v1/companies/{company}/accounting/posting-rules' => ['event_type' => 'test.other', 'lines' => [
                ['field' => 'total', 'side' => 'debit', 'account_id' => $tenant->id('acc_account')], ['field' => 'total', 'side' => 'credit', 'account_id' => $tenant->id('acc_free_asset')]]],
            'POST api/v1/companies/{company}/accounting/journals' => $journal,
            'PATCH api/v1/accounting/journals/{acc_journal}' => $journal,
            'POST api/v1/companies/{company}/accounting/recurring-journals' => ['name' => 'Hijack', 'frequency' => 'monthly', 'next_run_on' => '2027-01-01', 'lines' => $journal['lines']],
            'PATCH api/v1/accounting/recurring-journals/{acc_recurring_journal}' => ['lines' => $journal['lines']],
            'POST api/v1/companies/{company}/accounting/documents' => ['kind' => 'customer_invoice', 'party_id' => $tenant->id('customer'), 'branch_id' => $tenant->id('branch'), 'issue_date' => '2026-10-05', 'lines' => $documentLines],
            'PATCH api/v1/accounting/documents/{acc_document}' => ['party_id' => $tenant->id('customer'), 'branch_id' => $tenant->id('branch'), 'lines' => $documentLines],
            'POST api/v1/accounting/credit-notes/{acc_credit_note}/allocate' => ['allocations' => [['document_id' => $tenant->id('acc_invoice'), 'amount' => '10.00']]],
            'POST api/v1/companies/{company}/accounting/payments' => ['kind' => 'receipt', 'party_id' => $tenant->id('customer'), 'bank_account_id' => $tenant->id('acc_bank_account'), 'date' => '2026-10-06', 'amount' => '50.00',
                'allocations' => [['document_id' => $tenant->id('acc_invoice'), 'amount' => '10.00']]],
            'POST api/v1/accounting/payments/{acc_payment}/allocate' => ['allocations' => [['document_id' => $tenant->id('acc_invoice'), 'amount' => '10.00']]],
            'POST api/v1/companies/{company}/accounting/bank-accounts' => ['name' => 'Hijack', 'kind' => 'bank', 'account_id' => $tenant->id('acc_free_asset'), 'currency' => 'KES', 'payment_method_id' => $tenant->id('payment_method')],
            'PATCH api/v1/accounting/bank-accounts/{acc_bank_account}' => ['payment_method_id' => $tenant->id('payment_method')],
            'POST api/v1/accounting/bank-statement-lines/{acc_bank_statement_line}/post' => ['account_id' => $tenant->id('acc_account'), 'cost_centre_id' => $tenant->id('cost_centre')],
            'POST api/v1/companies/{company}/accounting/bank-rules' => ['name' => 'Hijack', 'contains' => 'Hijack', 'account_id' => $tenant->id('acc_account'), 'cost_centre_id' => $tenant->id('cost_centre'), 'bank_account_id' => $tenant->id('acc_bank_account')],
            'PUT api/v1/accounting/budgets/{acc_budget}/cells' => ['cells' => [['account_id' => $tenant->id('acc_account'), 'cost_centre_id' => $tenant->id('cost_centre'), 'month' => 11, 'amount' => '10.00']]],
            // Procurement (docs/modules/procurement.md): bodies naming A's own branch, location, dimensions, supplier, items and documents.
            'POST api/v1/procurement/requisitions' => ['branch_id' => $tenant->id('branch'), 'location_id' => $tenant->id('location'), 'department_id' => $tenant->id('department'), 'lines' => $procurementLines],
            'PATCH api/v1/procurement/requisitions/{procurement_requisition}' => ['location_id' => $tenant->id('location'), 'department_id' => $tenant->id('department'), 'lines' => $procurementLines],
            'POST api/v1/procurement/rfqs' => ['branch_id' => $tenant->id('branch'), 'requisition_id' => $tenant->id('procurement_requisition_approved'), 'title' => 'Hijack check',
                'lines' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1', 'requisition_line_id' => $tenant->id('procurement_requisition_line')]]],
            'PATCH api/v1/procurement/rfqs/{procurement_rfq}' => ['lines' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1']]],
            'POST api/v1/procurement/quotes' => ['rfq_id' => $tenant->id('procurement_rfq_issued'), 'party_id' => $tenant->id('party'), 'quote_date' => now()->toDateString(), 'currency' => 'KES',
                'lines' => [['rfq_line_id' => $tenant->id('procurement_rfq_line'), 'unit_price_minor' => '117000']]],
            'POST api/v1/procurement/tenders' => ['branch_id' => $tenant->id('branch'), 'requisition_id' => $tenant->id('procurement_requisition_approved'), 'title' => 'Hijack check', 'kind' => 'invited',
                'deadline_at' => now()->addDay()->toIso8601String(), 'opening_at' => now()->addDay()->toIso8601String(), 'lines' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1']]],
            'PATCH api/v1/procurement/tenders/{procurement_tender}' => ['lines' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'qty' => '1']]],
            'POST api/v1/procurement/tenders/{procurement_tender}/bidders' => ['party_id' => $tenant->id('party'), 'email' => 'hijack-check@supplier.example.com'],
            'PUT api/v1/procurement/bids/{procurement_bid}/scores' => ['scores' => [['criterion_id' => $tenant->id('procurement_criterion'), 'score' => 5]]],
            'POST api/v1/procurement/orders' => ['branch_id' => $tenant->id('branch'), 'party_id' => $tenant->id('party'), 'location_id' => $tenant->id('location'), 'lines' => $procurementOrderLines],
            'PATCH api/v1/procurement/orders/{procurement_order}' => ['party_id' => $tenant->id('party'), 'location_id' => $tenant->id('location'), 'lines' => $procurementOrderLines],
            'POST api/v1/procurement/receipts' => ['order_id' => $tenant->id('procurement_order_approved'), 'location_id' => $tenant->id('location'),
                'lines' => [['order_line_id' => $tenant->id('procurement_order_line'), 'received_qty' => '1']]],
            'PATCH api/v1/procurement/receipts/{procurement_receipt}' => ['location_id' => $tenant->id('location'), 'lines' => [['order_line_id' => $tenant->id('procurement_order_line'), 'received_qty' => '1']]],
            'POST api/v1/procurement/invoices' => ['order_id' => $tenant->id('procurement_order_approved'), 'supplier_reference' => 'HIJACK-CHECK', 'invoice_date' => now()->toDateString(),
                'lines' => [['order_line_id' => $tenant->id('procurement_order_line'), 'qty' => '1', 'tax_code_id' => $tenant->id('tax_code')]]],
            'PATCH api/v1/procurement/invoices/{procurement_invoice}' => ['lines' => [['order_line_id' => $tenant->id('procurement_order_line'), 'qty' => '1', 'tax_code_id' => $tenant->id('tax_code')]]],
            'POST api/v1/procurement/landed-costs' => ['branch_id' => $tenant->id('branch'), 'cost_date' => now()->toDateString(), 'method' => 'value',
                'charges' => [['description' => 'Hijack check', 'party_id' => $tenant->id('party'), 'currency' => 'KES', 'amount_minor' => '1000']], 'receipt_ids' => [$tenant->id('procurement_receipt')]],
            'PATCH api/v1/procurement/landed-costs/{procurement_landed_cost}' => ['charges' => [['description' => 'Hijack check', 'party_id' => $tenant->id('party'), 'currency' => 'KES', 'amount_minor' => '1000']]],
            // CRM (docs/modules/crm.md): the company's settings and configuration, records and their links.
            'PUT api/v1/crm/companies/{company}/settings' => ['default_team_id' => $tenant->id('crm_team')],
            'POST api/v1/crm/pipelines' => ['company_id' => $tenant->id('company'), 'name' => 'Hijack pipeline'],
            'POST api/v1/crm/options' => ['company_id' => $tenant->id('company'), 'kind' => 'source', 'name' => 'Hijack source'],
            'POST api/v1/crm/teams' => ['company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'), 'name' => 'Hijack team', 'leader_id' => $tenant->id('user'),
                'members' => [['user_id' => $tenant->id('user')]]],
            'PATCH api/v1/crm/teams/{crm_team}' => ['branch_id' => $tenant->id('branch'), 'leader_id' => $tenant->id('user'), 'members' => [['user_id' => $tenant->id('user')]]],
            'POST api/v1/crm/assignment-rules' => ['company_id' => $tenant->id('company'), 'name' => 'Hijack rule', 'branch_id' => $tenant->id('branch'), 'assignee_type' => 'user',
                'user_id' => $tenant->id('user'), 'source_ids' => [$tenant->id('crm_source')]],
            'PATCH api/v1/crm/assignment-rules/{crm_assignment_rule}' => ['branch_id' => $tenant->id('branch'), 'assignee_type' => 'team', 'team_id' => $tenant->id('crm_team')],
            'POST api/v1/crm/lead-forms' => ['company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'), 'name' => 'Hijack form',
                'source_id' => $tenant->id('crm_source'), 'team_id' => $tenant->id('crm_team')],
            'PATCH api/v1/crm/lead-forms/{crm_lead_form}' => ['branch_id' => $tenant->id('branch'), 'source_id' => $tenant->id('crm_source'), 'team_id' => $tenant->id('crm_team')],
            'POST api/v1/crm/leads' => ['branch_id' => $tenant->id('branch'), 'name' => 'Hijack lead', 'source_id' => $tenant->id('crm_source'), 'owner_id' => $tenant->id('user'),
                'team_id' => $tenant->id('crm_team'), 'channel' => 'manual'],
            'PATCH api/v1/crm/leads/{crm_lead}' => ['source_id' => $tenant->id('crm_source'), 'owner_id' => $tenant->id('user'), 'team_id' => $tenant->id('crm_team')],
            'POST api/v1/crm/leads/{crm_lead}/assign' => ['owner_id' => $tenant->id('user'), 'team_id' => $tenant->id('crm_team')],
            'POST api/v1/crm/leads/{crm_lead}/convert' => ['party_id' => $tenant->id('customer'), 'party' => ['company_id' => $tenant->id('company')],
                'opportunity' => ['pipeline_id' => $tenant->id('crm_pipeline'), 'stage_id' => $tenant->id('crm_stage')]],
            'POST api/v1/crm/opportunities' => ['branch_id' => $tenant->id('branch'), 'party_id' => $tenant->id('customer'), 'name' => 'Hijack deal', 'pipeline_id' => $tenant->id('crm_pipeline'),
                'stage_id' => $tenant->id('crm_stage'), 'owner_id' => $tenant->id('user'), 'team_id' => $tenant->id('crm_team'), 'value_minor' => '1000', 'currency' => 'KES'],
            'PATCH api/v1/crm/opportunities/{crm_opportunity}' => ['party_id' => $tenant->id('customer'), 'pipeline_id' => $tenant->id('crm_pipeline'), 'stage_id' => $tenant->id('crm_stage'),
                'owner_id' => $tenant->id('user'), 'team_id' => $tenant->id('crm_team')],
            'POST api/v1/crm/opportunities/{crm_opportunity}/win' => ['reason_id' => $tenant->id('crm_won_reason')],
            'POST api/v1/crm/opportunities/{crm_opportunity}/lose' => ['reason_id' => $tenant->id('crm_lost_reason')],
            'POST api/v1/crm/quotes', 'POST api/v1/crm/quotes/preview' => ['branch_id' => $tenant->id('branch'), 'party_id' => $tenant->id('customer'), 'opportunity_id' => $tenant->id('crm_opportunity'),
                'owner_id' => $tenant->id('user'), 'team_id' => $tenant->id('crm_team'), 'lines' => $crmLines],
            'PATCH api/v1/crm/quotes/{crm_quote}' => ['party_id' => $tenant->id('customer'), 'opportunity_id' => $tenant->id('crm_opportunity'), 'owner_id' => $tenant->id('user'),
                'team_id' => $tenant->id('crm_team'), 'lines' => $crmLines],
            'POST api/v1/crm/activities' => ['branch_id' => $tenant->id('branch'), 'kind' => 'task', 'subject' => 'Hijack check', 'starts_at' => '2026-12-02T08:00:00Z',
                'owner_id' => $tenant->id('user'), 'lead_id' => $tenant->id('crm_lead'), 'opportunity_id' => $tenant->id('crm_opportunity'), 'quote_id' => $tenant->id('crm_quote'), 'party_id' => $tenant->id('customer')],
            'PATCH api/v1/crm/activities/{crm_activity}' => ['owner_id' => $tenant->id('user'), 'lead_id' => $tenant->id('crm_lead'), 'opportunity_id' => $tenant->id('crm_opportunity'),
                'quote_id' => $tenant->id('crm_quote'), 'party_id' => $tenant->id('customer')],
            'POST api/v1/crm/emails' => ['to' => 'buyer-A@example.com', 'subject' => 'Hijack check', 'body' => 'Check', 'branch_id' => $tenant->id('branch'), 'lead_id' => $tenant->id('crm_lead'),
                'opportunity_id' => $tenant->id('crm_opportunity'), 'quote_id' => $tenant->id('crm_quote'), 'party_id' => $tenant->id('customer')],
            'POST api/v1/crm/targets' => ['company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'), 'subject_type' => 'user', 'subject_id' => $tenant->id('user'),
                'metric' => 'won_value', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'amount_minor' => '1000'],
            'PATCH api/v1/crm/targets/{crm_target}' => ['branch_id' => $tenant->id('branch'), 'subject_id' => $tenant->id('user')],
            // E-commerce (docs/modules/ecommerce.md): stores, phone and WhatsApp orders, rider assignment, riders, cash-ups, rider updates.
            'POST api/v1/ecommerce/stores' => ['company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'), 'location_id' => $tenant->id('location'),
                'handle' => 'hijack-'.substr($tenant->tenantId, 0, 8), 'name' => 'Hijack store', 'currency' => 'KES', 'price_list_id' => $tenant->id('price_list'),
                'delivery_tax_code_id' => $tenant->id('tax_code'), 'payment_methods' => [['kind' => 'mpesa', 'payment_method_id' => $tenant->id('payment_method')]]],
            'PATCH api/v1/ecommerce/stores/{ecom_store}' => ['branch_id' => $tenant->id('branch'), 'location_id' => $tenant->id('location'), 'price_list_id' => $tenant->id('price_list'),
                'delivery_tax_code_id' => $tenant->id('tax_code')],
            'POST api/v1/ecommerce/stores/{ecom_store}/quote' => ['lines' => [['product_id' => $tenant->id('ecom_product'), 'qty' => '1']], 'fulfilment' => 'delivery', 'zone_id' => $tenant->id('ecom_zone'),
                'pickup_location_id' => $tenant->id('location')],
            'POST api/v1/ecommerce/orders' => ['store_id' => $tenant->id('ecom_store'), 'lines' => [['product_id' => $tenant->id('ecom_product'), 'qty' => '1']], 'fulfilment' => 'delivery',
                'zone_id' => $tenant->id('ecom_zone'), 'pickup_location_id' => $tenant->id('location'), 'payment' => 'cod', 'contact' => ['name' => 'Phone order', 'phone' => '0712000999'],
                'address' => ['line' => 'Hijack road 1'], 'expected_total_minor' => '31250'],
            'POST api/v1/ecommerce/conversations/{ecom_conversation}/order' => ['lines' => [['product_id' => $tenant->id('ecom_product'), 'qty' => '1']], 'fulfilment' => 'delivery',
                'zone_id' => $tenant->id('ecom_zone'), 'pickup_location_id' => $tenant->id('location'), 'payment' => 'cod', 'contact' => ['name' => 'Chat order', 'phone' => '0712000998'],
                'address' => ['line' => 'Hijack road 2'], 'expected_total_minor' => '31250'],
            'POST api/v1/ecommerce/deliveries/{ecom_delivery}/assign' => ['rider_id' => $tenant->id('ecom_rider')],
            'POST api/v1/ecommerce/riders' => ['company_id' => $tenant->id('company'), 'user_id' => $tenant->id('manager'), 'employee_id' => $tenant->id('hr_employee')],
            'PATCH api/v1/ecommerce/riders/{ecom_rider}' => ['employee_id' => $tenant->id('hr_employee'), 'vehicle' => 'Motorbike'],
            'POST api/v1/ecommerce/cashups' => ['rider_id' => $tenant->id('ecom_rider'), 'currency' => 'KES', 'counted_minor' => '32656'],
            'POST api/v1/ecommerce/rider/updates' => ['updates' => [['id' => (string) Str::uuid7(), 'delivery_id' => $tenant->id('ecom_delivery'), 'kind' => 'location',
                'latitude' => '-1.28', 'longitude' => '36.82', 'occurred_at' => now()->toIso8601String()]]],
            default => null,
        };
    }

    /** A valid automation rule body (on the test request type, notifying the Admin role). */
    private static function automationRule(): array
    {
        return [
            'name' => 'Hijack rule',
            'document_type' => TestRequestType::KEY,
            'trigger' => ['type' => 'record_created'],
            'actions' => [['type' => 'notify', 'to' => ['role:admin'], 'subject' => 'New {note}', 'message' => 'A request was created.']],
        ];
    }

    /**
     * Fails when any row of $holder (read in its context) holds one of
     * $other's ids in a uuid column, or in a json/jsonb column's text.
     */
    private function assertNoRowOf(TenantFixture $holder, string $verb, TenantFixture $other): void
    {
        $ids = array_values(array_filter($this->identifiersOf($other), fn ($v) => Str::isUuid($v)));
        $array = '{'.implode(',', $ids).'}';
        $patterns = '{'.implode(',', array_map(fn ($id) => "%{$id}%", $ids)).'}';
        $scanned = 0;

        $this->asTenant($holder->tenantId, function () use ($array, $patterns, $verb, &$scanned) {
            foreach ($this->tenantTables() as $table) {
                $columns = DB::select("
                    select column_name, data_type from information_schema.columns
                    where table_schema = 'public' and table_name = ? and data_type in ('uuid', 'json', 'jsonb')
                ", [$table]);

                foreach ($columns as $column) {
                    $sql = $column->data_type === 'uuid'
                        ? "select count(*) from \"{$table}\" where \"{$column->column_name}\" = any(?::uuid[])"
                        : "select count(*) from \"{$table}\" where \"{$column->column_name}\"::text like any(?::text[])";
                    $found = $this->rows($sql, [$column->data_type === 'uuid' ? $array : $patterns]);
                    $this->assertSame(0, $found, "{$table}.{$column->column_name}: {$found} row(s) of tenant A {$verb} ids of tenant B");
                    $scanned++;
                }
            }
        });

        $this->assertGreaterThan(20, $scanned);
    }

    /**
     * Query parameters a GET route reads: its Form Request's rule keys and
     * literal query()/input()/... reads in that request class (and its
     * parents) and in the controller method.
     *
     * @return list<string>
     */
    private function queryParametersRead(RoutingRoute $route): array
    {
        $sources = [];
        $uses = $route->getAction('uses');

        if (is_string($uses)) {
            [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];
            $reflection = new ReflectionMethod($class, $method);
            $lines = file($reflection->getFileName());
            $sources[] = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        }

        $parameters = [];
        $request = $this->formRequestOf($route);

        if ($request !== null) {
            $parameters = $this->ruleKeys($route, 'GET', $this->tenants->a);

            for ($class = new ReflectionClass($request); $class && $class->getName() !== FormRequest::class; $class = $class->getParentClass()) {
                $sources[] = file_get_contents($class->getFileName());
            }
        }

        foreach ($sources as $source) {
            preg_match_all('/->(?:query|input|get|string|integer|boolean|has|filled|validated|date|enum|collect)\(\s*[\'"]([A-Za-z0-9_.\-\[\]]+)[\'"]/', $source, $matches);
            array_push($parameters, ...$matches[1]);
        }

        return array_values(array_unique(array_map(fn ($p) => Str::before($p, '.'), $parameters)));
    }

    /** @param list<string> $items */
    private function list(array $items): string
    {
        return implode(', ', $items);
    }

    private function lastVerificationCode(): string
    {
        $codes = [];

        foreach (Notification::sentNotifications() as $byId) {
            foreach ($byId as $byClass) {
                foreach ($byClass[VerificationCode::class] ?? [] as $sent) {
                    $codes[] = $sent['notification']->code;
                }
            }
        }

        $this->assertNotEmpty($codes);

        return end($codes);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::selectOne(
            "select exists(select 1 from information_schema.columns where table_schema = 'public' and table_name = ? and column_name = ?) as e",
            [$table, $column],
        )->e;
    }

    private function rows(string $sql, array $bindings = []): int
    {
        return (int) DB::selectOne(str_replace('count(*)', 'count(*) as n', $sql), $bindings)->n;
    }

    private function asTenant(string $tenantId, callable $fn): mixed
    {
        return app(TenantContext::class)->run($tenantId, $fn);
    }
}
