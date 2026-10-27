<?php

namespace Tests\Support;

use App\Core\Api\Sandbox\SandboxOutbox;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Automation\Events\RecordChanged;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Billing\Models\BillingDeal;
use App\Core\Billing\Operator\Operator;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\DataExport\Jobs\BuildTenantExport;
use App\Core\DataExport\Models\TenantExport;
use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Fiscal\FiscalSources;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Models\User;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Identity\Pin\OverrideVerifier;
use App\Core\Limits\Allowances;
use App\Core\Limits\PlanAllowances;
use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\Marketplace\Connectors\FirstParty;
use App\Core\Marketplace\Models\MarketplacePayable;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Notifications\Notifier;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Privacy\Models\PrivacyRequest;
use App\Core\Privacy\PrivacyRequests;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Models\SsoIdentity;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Accounting\Models\QueuedSource;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Ecommerce\Customers\CustomerCodeNotification;
use Modules\Ecommerce\Models\Conversation;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\WhatsApp\FakeWhatsAppProvider;
use Modules\HR\Events\AttendancePeriodClosed;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Models\Employee;
use Modules\Manufacturing\Models\Backflush;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Tests\Concerns\FixturePacks;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Stock\Lots;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Tenders\Bidders;
use Modules\Restaurant\Models\Settlement;
use Modules\Restaurant\Models\Ticket;
use PHPUnit\Framework\Assert;
use Tests\Support\Automation\FakeHostResolver;
use Tests\Support\Configuration\TestLayoutKind;
use Tests\Support\Fiscal\TestFiscalSource;
use Tests\Support\Limits\FixedAllowances;
use Tests\Support\Sso\FakeSamlIdp;
use Tests\Support\Workflow\TestDocuments;
use Tests\Support\Workflow\TestOrderType;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * TEN-01: two tenants, A and B, built the way production builds them:
 * self sign-up and verification, then the API as each Owner (companies,
 * branches, locations, an archived location, a paired device, a custom
 * role, an accepted and a pending invitation, an assignment, tenant and
 * reporting currencies, exchange rates and a rate alert, tax codes and
 * rates from the country pack, a tax category and a price list with a
 * price for the item's box, a
 * master data sharing setting, a shared customer and a per-company
 * supplier, item categories and an item with another unit, barcodes and
 * an image, configured payment methods, departments, cost centres and
 * projects, a published workflow with a document in it that created an
 * order, working hours, notification texts, settings and preferences, and
 * a notification sent to the owner and the manager, an automation rule
 * and its run, payment intents made at the till and money received that
 * matched nothing, fiscal settings and a submission to the authority, and
 * the POS module with a till's ranges, shift, sales, a void, a refund and a
 * cash movement, and a full data export). Field rules, limit
 * rules and module flags have
 * no API yet and are written through their models in the tenant's own
 * context. Every tenant table ends up with rows in both tenants, so a
 * missing filter shows up as a leak.
 *
 * A signs up with an email address, B with a phone number, so the CSV and
 * body checks cover both kinds of contact.
 */
final class TwoTenants
{
    public const PASSWORD = 'violet-harbour-42';

    public const MODULE = 'isolation';

    /**
     * MKT-02 (ADR 024): a listed app both tenants install, committed on the
     * schema owner's connection (review is the platform's, outside the test
     * transaction) and removed after the test.
     */
    public const MARKETPLACE_APP = '019a0000-0000-7000-8000-0000000fe001';

    public const MARKETPLACE_CLIENT = '019a0000-0000-7000-8000-0000000fe002';

    public const MARKETPLACE_VERSION = '019a0000-0000-7000-8000-0000000fe003';

    public const MARKETPLACE_SECRET = 'isolation-client-secret-0123456789abcdefghij';

    public const MARKETPLACE_REDIRECT = 'https://isolation.example.com/callback';

    /** RFC 7636 appendix B: the verifier and its S256 challenge. */
    public const PKCE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    public const PKCE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** AUTH-06: the owner's POS PIN in both tenants (6 digits: owners approve overrides, AUTH-08). */
    public const PIN = '482619';

    /** The current build's host clock, to name a suspension in setup failures. */
    private static ?HostClock $clock = null;

    private function __construct(
        public readonly TenantFixture $a,
        public readonly TenantFixture $b,
    ) {}

    public static function build(TestCase $test): self
    {
        self::$clock = HostClock::start();
        Notification::fake();
        Mail::fake();
        Storage::fake('media');
        // AUTO-03: webhooks go nowhere; the receiver's name resolves to a public address.
        Http::fake([
            'https://hooks.example.com/*' => Http::response('received', 200),
            // Payments: Daraja's sandbox answers an OAuth token and accepts STK pushes.
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok-isolation', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => fn () => Http::response(['MerchantRequestID' => 'm-'.Str::random(8), 'CheckoutRequestID' => 'ws_CO_'.Str::random(16), 'ResponseCode' => '0']),
        ]);
        // Payments: callbacks come from the test client's address; fiscal: the
        // test document source (as the POS registers its sales).
        config([
            'payments.mpesa.base_url' => 'https://sandbox.safaricom.co.ke',
            'payments.callback_base_url' => 'https://api.example.com',
            'payments.mpesa.callback_ips' => ['127.0.0.1'],
            'payments.drivers' => [],
        ]);
        TestFiscalSource::reset();
        app(FiscalSources::class)->register(TestFiscalSource::KEY, TestFiscalSource::class);
        app()->instance(HostResolver::class, new FakeHostResolver(['hooks.example.com' => [['93.184.216.34']]]));
        app(ModuleRegistry::class)->register(self::MODULE);
        // WF-01: the test document types (a request that creates orders).
        TestDocuments::reset();
        // AUTO-01: the fixture raises RecordChanged for requests as their module would.
        $requests = new TestRequestType;
        $requests->raisesRecords = true;
        app(DocumentTypeRegistry::class)->register($requests);
        app(DocumentTypeRegistry::class)->register(TestOrderType::class);
        // LAY-06: a configuration kind (as a layout designer registers one).
        TestLayoutKind::register();

        // SUB-03: the operator (the platform's seller), with fake fiscal and payment drivers.
        $test->artisan('platform:operator', ['--country' => ['KE'], '--fake' => true])->assertSuccessful();
        app(Operator::class)->forget();
        self::marketplaceApp($test);
        // MKT-03: a payout in the operator's developers' ledger.
        app(TenantContext::class)->run((string) app(Operator::class)->id(), fn () => MarketplacePayable::query()->create([
            'developer_id' => FirstParty::DEVELOPER, 'kind' => MarketplacePayable::PAYOUT, 'currency' => 'KES', 'amount_minor' => 100,
            'reference' => 'ISOLATION-1', 'recorded_by' => (string) Str::uuid7(), 'occurred_at' => now(),
        ]));

        return new self(
            self::tenant($test, 'a', ['email' => 'owner-a@example.com']),
            self::tenant($test, 'b', ['phone' => '+254700000201']),
        );
    }

    /** @param array{email?: string, phone?: string} $login */
    private static function tenant(TestCase $test, string $key, array $login): TenantFixture
    {
        $upper = strtoupper($key);

        // AUTH-01: sign-up, then the code sent to the contact.
        $challenge = self::ok($test->postJson('/api/v1/auth/sign-up', array_merge([
            'name' => "Owner {$upper}",
            'password' => self::PASSWORD,
            'country' => 'KE',
            'locale' => 'en',
            'business_name' => "Tenant {$upper} Stores",
        ], $login)), 201)->json('challenge_id');

        $verified = self::ok($test->postJson('/api/v1/auth/verify', [
            'challenge_id' => $challenge,
            'code' => self::last(VerificationCode::class)->code,
        ]));
        $ownerToken = $verified->json('token');
        $ownerId = $verified->json('user.id');
        $tenantId = $verified->json('user.tenant_id');
        $owner = ['Authorization' => 'Bearer '.$ownerToken];

        $signUpCompany = self::ok($test->getJson('/api/v1/companies', $owner))->json('data.0.id');

        $company = self::ok($test->postJson('/api/v1/companies', ['name' => "Company {$upper}", 'country' => 'KE'], $owner), 201)->json('data.id');
        $branch = self::ok($test->postJson("/api/v1/companies/{$company}/branches", ['name' => "Branch {$upper}", 'code' => "ISO-{$upper}"], $owner), 201)->json('data.id');
        $location = self::ok($test->postJson("/api/v1/branches/{$branch}/locations", ['name' => "Outlet {$upper}", 'type' => 'outlet'], $owner), 201)->json('data.id');
        $archived = self::ok($test->postJson("/api/v1/branches/{$branch}/locations", ['name' => "Closed {$upper}", 'type' => 'store'], $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/locations/{$archived}/archive", [], $owner));

        // CUR-01, CUR-02: sign-up activated KES and USD; USD reports for the company.
        self::ok($test->putJson("/api/v1/companies/{$company}/currencies", ['base_currency' => 'KES', 'reporting_currencies' => ['USD']], $owner));
        // CUR-03, CUR-07: two USD/KES shop rates; the second moves past the tolerance (an alert).
        self::ok($test->postJson("/api/v1/companies/{$company}/exchange-rates", ['base' => 'USD', 'quote' => 'KES', 'mid' => '129.5', 'effective_at' => '2026-10-01T08:00:00Z'], $owner), 201);
        self::ok($test->postJson("/api/v1/companies/{$company}/exchange-rates", ['base' => 'USD', 'quote' => 'KES', 'mid' => '140', 'effective_at' => '2026-10-02T08:00:00Z'], $owner), 201)
            ->assertJsonPath('meta.warning.code', 'rate_tolerance_exceeded');
        $tenantCurrency = collect(self::ok($test->getJson('/api/v1/tenant/currencies', $owner))->json('data'))->firstWhere('code', 'USD')['id'];

        // MD-03, CP-01, CP-02: the company got the KE pack's tax codes; a rate is
        // entered (a test figure, after the researched pack rate's start), the pack applied again (adds nothing), a shared
        // tax category with a default code, and a default price list.
        $taxCode = collect(self::ok($test->getJson("/api/v1/companies/{$company}/tax-codes", $owner))->json('data'))->firstWhere('code', 'VAT_STD')['id'];
        self::ok($test->postJson("/api/v1/tax-codes/{$taxCode}/rates", ['rate' => '12.5', 'effective_from' => '2026-02-01'], $owner), 201);
        self::ok($test->postJson("/api/v1/companies/{$company}/tax-codes/apply-pack", [], $owner));
        $taxCategory = self::ok($test->postJson('/api/v1/tax-categories', [
            'name' => "Goods {$upper}",
            'codes' => [['company_id' => $company, 'tax_code_id' => $taxCode]],
        ], $owner), 201)->json('data.id');
        $priceList = self::ok($test->postJson("/api/v1/companies/{$company}/price-lists", [
            'name' => "Retail {$upper}", 'currency' => 'KES', 'tax_inclusive' => true, 'is_default' => true,
        ], $owner), 201)->json('data.id');

        // TEN-08, MD-01, MD-07: suppliers kept per company; a shared customer and
        // the company's supplier (with its price list), renamed once (history).
        self::ok($test->putJson('/api/v1/master-data/settings', ['data_type' => 'suppliers', 'mode' => 'per_company'], $owner));
        $partyPhone = $key === 'a' ? '+254700000301' : '+254700000302';
        $customer = self::ok($test->postJson('/api/v1/parties', [
            'kind' => 'organisation', 'name' => "Customer {$upper}", 'roles' => ['customer'], 'tags' => ['vip'],
            'phones' => [['number' => $partyPhone]],
        ], $owner), 201)->json('data.id');
        $party = self::ok($test->postJson('/api/v1/parties', [
            'kind' => 'organisation', 'name' => "Supplier {$upper}", 'roles' => ['supplier'], 'tax_id' => "P00000000{$upper}",
            'company_id' => $company, 'price_list_id' => $priceList,
        ], $owner), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/parties/{$party}", ['legal_name' => "Supplier {$upper} Limited"], $owner));

        // MD-02: units from sign-up; a category under another; an item with a
        // box of 12, a barcode for each unit and an image, renamed once (history).
        $uoms = collect(self::ok($test->getJson('/api/v1/uoms?per_page=200', $owner))->json('data'))->pluck('id', 'code');
        $parentCategory = self::ok($test->postJson('/api/v1/item-categories', ['name' => "Goods {$upper}"], $owner), 201)->json('data.id');
        $itemCategory = self::ok($test->postJson('/api/v1/item-categories', ['name' => "Goods {$upper} sub", 'parent_id' => $parentCategory], $owner), 201)->json('data.id');
        $item = self::ok($test->postJson('/api/v1/items', [
            'code' => "ITEM-{$upper}", 'name' => "Item {$upper}", 'type' => 'stock', 'base_uom_id' => $uoms['EA'],
            'category_id' => $itemCategory, 'tax_category_id' => $taxCategory,
            'uoms' => [['uom_id' => $uoms['BOX'], 'factor' => '12']],
            'barcodes' => [['barcode' => '6161000000001'], ['barcode' => '6161000000018', 'uom_id' => $uoms['BOX']]],
        ], $owner), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/items/{$item}", ['name' => "Article {$upper}"], $owner));
        // MD-03 follow-up: the item's box priced in the default list, then repriced (history).
        $itemPrice = self::ok($test->postJson("/api/v1/price-lists/{$priceList}/prices", [
            'item_id' => $item, 'uom_id' => $uoms['BOX'], 'amount_minor' => '120000', 'currency' => 'KES',
        ], $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/price-lists/{$priceList}/prices", [
            'item_id' => $item, 'uom_id' => $uoms['BOX'], 'amount_minor' => '125000', 'currency' => 'KES',
        ], $owner));
        $itemImage = self::ok($test->post("/api/v1/items/{$item}/images", ['image' => UploadedFile::fake()->image('item.jpg', 8, 8)], [...$owner, 'Accept' => 'application/json']), 201)
            ->json('data.images.0.id');

        // CF-01..CF-03: a custom field shown on the POS (changed once: history), a
        // file field with an uploaded file, both set on the item.
        $customField = self::ok($test->postJson('/api/v1/custom-fields', [
            'entity' => 'item', 'key' => 'colour', 'label' => "Colour {$upper}", 'type' => 'select', 'show_on_pos' => true,
            'options' => [['value' => 'red', 'label' => 'Red'], ['value' => "only_{$key}", 'label' => "Only {$upper}"]],
        ], $owner), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/custom-fields/{$customField}", ['help' => "Shade {$upper}"], $owner));
        self::ok($test->postJson('/api/v1/custom-fields', ['entity' => 'item', 'key' => 'manual', 'label' => "Manual {$upper}", 'type' => 'file'], $owner), 201);
        $customFile = self::ok($test->post('/api/v1/custom-field-files', [
            'entity' => 'item', 'field' => 'manual', 'file' => UploadedFile::fake()->create("manual-{$key}.pdf", 4, 'application/pdf'),
        ], [...$owner, 'Accept' => 'application/json']), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/items/{$item}", ['custom' => ['colour' => 'red', 'manual' => $customFile]], $owner));

        // CF-04, CF-05: a petty cash form with a header field, a money line field and
        // attachments; a draft record with a line and an uploaded file, changed once.
        $formType = self::ok($test->postJson('/api/v1/custom-form-types', [
            'key' => 'petty_cash', 'name' => "Petty cash {$upper}", 'workflow' => true, 'has_lines' => true, 'attachments' => true,
        ], $owner), 201)->json('data.id');
        self::ok($test->postJson('/api/v1/custom-fields', ['entity' => 'custom_form:petty_cash', 'key' => 'reason', 'label' => "Reason {$upper}", 'type' => 'text'], $owner), 201);
        self::ok($test->postJson('/api/v1/custom-fields', ['entity' => 'custom_form_line:petty_cash', 'key' => 'amount', 'label' => "Amount {$upper}", 'type' => 'money'], $owner), 201);
        $formFile = self::ok($test->post("/api/v1/custom-form-types/{$formType}/attachments", [
            'file' => UploadedFile::fake()->create("receipt-{$key}.pdf", 4, 'application/pdf'),
        ], [...$owner, 'Accept' => 'application/json']), 201)->json('data.id');
        $formRecord = self::ok($test->postJson("/api/v1/custom-form-types/{$formType}/records", [
            'company_id' => $company, 'branch_id' => $branch, 'custom' => ['reason' => "Fuel {$upper}"],
            'lines' => [['custom' => ['amount' => ['amount_minor' => '150000', 'currency' => 'KES']]]], 'attachments' => [$formFile],
        ], $owner), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/custom-form-records/{$formRecord}", ['custom' => ['reason' => "Fuel and water {$upper}"]], $owner));

        // MD-04: the company's seeded payment methods; M-Pesa configured
        // (secrets stored encrypted) and switched on, then moved to the top.
        $methods = collect(self::ok($test->getJson("/api/v1/companies/{$company}/payment-methods", $owner))->json('data'));
        $paymentMethod = $methods->firstWhere('provider', 'mpesa_ke')['id'];
        self::ok($test->patchJson("/api/v1/payment-methods/{$paymentMethod}", [
            'active' => true, 'settings' => ['shortcode' => "17437{$key}"],
            'secrets' => ['consumer_key' => "key-{$key}", 'consumer_secret' => "secret-{$key}", 'passkey' => "pass-{$key}"],
        ], $owner));
        self::ok($test->putJson("/api/v1/companies/{$company}/payment-methods/order", [
            'ids' => [$paymentMethod, ...$methods->pluck('id')->reject(fn ($id) => $id === $paymentMethod)->values()->all()],
        ], $owner));

        // MD-05: a department, cost centre and project, each under a parent and owned by the Owner, renamed once (history).
        $dimensions = [];
        foreach (['department' => 'departments', 'cost_centre' => 'cost-centres', 'project' => 'projects'] as $type => $path) {
            $parent = self::ok($test->postJson("/api/v1/companies/{$company}/{$path}", ['code' => "{$upper}-ROOT", 'name' => "Root {$upper}"], $owner), 201)->json('data.id');
            $child = self::ok($test->postJson("/api/v1/companies/{$company}/{$path}", [
                'code' => "{$upper}-1", 'name' => "Unit {$upper}", 'parent_id' => $parent, 'owner_user_id' => $ownerId,
            ], $owner), 201)->json('data.id');
            self::ok($test->patchJson("/api/v1/{$path}/{$child}", ['name' => "Unit {$upper} renamed"], $owner));
            $dimensions[$type] = $child;
            $dimensions["{$type}_parent"] = $parent;
        }

        // WF-02..WF-11: the company's flow from the default, its draft replaced
        // by one that creates an order (WF-07) and published; a document started
        // (the module's call) and moved once through the API; working hours (WF-09).
        $workflow = self::ok($test->postJson('/api/v1/workflows', ['document_type' => TestRequestType::KEY, 'company_id' => $company], $owner), 201)->json('data.id');
        self::ok($test->putJson("/api/v1/workflows/{$workflow}/draft", ['graph' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'order', 'type' => 'action', 'name' => "Order {$upper}", 'action' => 'create_document', 'config' => ['mapping' => 'order', 'on_cancel' => 'cancel']],
                ['id' => 'review', 'type' => 'stage', 'name' => "Review {$upper}", 'due' => ['amount' => 8, 'unit' => 'business_hours']],
                ['id' => 'check', 'type' => 'stage', 'name' => "Check {$upper}"],
                ['id' => 'end', 'type' => 'end', 'outcome' => 'approved'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'order'], ['from' => 'order', 'to' => 'review'],
                ['from' => 'review', 'to' => 'check'], ['from' => 'check', 'to' => 'end'],
            ],
        ]], $owner));
        $workflowVersion = self::ok($test->postJson("/api/v1/workflows/{$workflow}/publish", [], $owner))->json('data.published.id');
        $document = app(TenantContext::class)->run($tenantId, function () use ($company, $branch, $ownerId, $upper) {
            $id = TestDocuments::create(TestRequestType::KEY, ['total' => ['amount_minor' => '100000', 'currency' => 'KES'], 'note' => "Request {$upper}"], new DocumentScope($company, $branch));
            app(WorkflowEngine::class)->start(TestRequestType::KEY, $id, User::findOrFail($ownerId));

            return $id;
        });
        self::ok($test->postJson('/api/v1/document-workflows/'.TestRequestType::KEY."/{$document}/move", [], $owner));
        self::ok($test->putJson("/api/v1/companies/{$company}/business-hours", ['hours' => ['mon' => [['08:00', '17:00']], 'sat' => [['09:00', '13:00']]]], $owner));

        // LAY-06: the company's layout, published, with a new draft open.
        $configDraft = self::ok($test->postJson('/api/v1/config/'.TestLayoutKind::KEY, [
            'scope_type' => 'company', 'scope_id' => $company, 'name' => "Layout {$upper}", 'payload' => ['columns' => [['id' => 'name']]],
        ], $owner), 201)->json('data');
        $configDocument = $configDraft['id'];
        self::ok($test->postJson('/api/v1/config/'.TestLayoutKind::KEY."/{$configDocument}/publish", ['revision' => $configDraft['draft']['revision']], $owner));
        self::ok($test->putJson('/api/v1/config/'.TestLayoutKind::KEY."/{$configDocument}/draft", ['payload' => ['columns' => [['id' => 'code']]]], $owner));

        // TPL-01: a template preview the owner made. Its PDF is kept for 10 minutes in
        // production; the isolation walk can outlast that, so the fixture keeps it a day.
        config(['document_templates.preview_minutes' => 24 * 60]);
        $templatePreview = basename(dirname((string) parse_url(self::ok($test->postJson('/api/v1/templates/preview', [
            'type' => 'sales.quote', 'payload' => DefaultTemplates::for('sales.quote'),
        ], $owner))->json('data.pdf_url'), PHP_URL_PATH)));
        // BR-02, BR-05: a brand asset (a logo) and a custom domain, still pending.
        $brandAsset = self::ok($test->post('/api/v1/branding/assets', ['kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 8, 8)], [...$owner, 'Accept' => 'application/json']), 201)->json('data.id');
        $tenantDomain = self::ok($test->postJson('/api/v1/branding/domains', ['host' => "erp-{$key}.example.org"], $owner), 201)->json('data.id');
        // AUTH-11: an SSO email domain (pending), an Entra connection and the owner linked to an IdP subject.
        $ssoDomain = self::ok($test->postJson('/api/v1/sso/domains', ['domain' => "staff-{$key}.example.org"], $owner), 201)->json('data.id');
        $ssoConnection = self::ok($test->postJson('/api/v1/sso/connections', [
            'provider' => 'entra', 'name' => "Entra {$upper}", 'client_id' => (string) Str::uuid(), 'client_secret' => "secret-{$key}", 'directory_id' => (string) Str::uuid(),
        ], $owner), 201)->json('data.id');
        $ssoIdentity = app(TenantContext::class)->run($tenantId, fn () => SsoIdentity::create([
            'user_id' => $ownerId, 'connection_id' => $ssoConnection, 'subject' => "subject-{$key}", 'email' => "owner-{$key}@staff-{$key}.example.org",
        ])->id);
        // AUTH-11: an enabled SAML connection (no network to start a sign-in) for linking one's own account.
        $ssoLive = self::ok($test->postJson('/api/v1/sso/connections', [
            'provider' => 'saml', 'name' => "SAML {$upper}", 'idp_entity_id' => "https://idp-{$key}.example.org", 'idp_sso_url' => "https://idp-{$key}.example.org/sso",
            'idp_certificates' => [FakeSamlIdp::pair()['cert']],
        ], $owner), 201)->json('data.id');
        app(TenantContext::class)->run($tenantId, fn () => SsoConnection::query()->whereKey($ssoLive)->update(['enabled' => true, 'tested_at' => now()]));

        // TEN-05: a device, paired with its one-time code.
        $device = self::ok($test->postJson("/api/v1/locations/{$location}/devices", ['name' => "Till {$upper}"], $owner), 201)->json('data.id');
        $code = self::ok($test->postJson("/api/v1/devices/{$device}/pairing-code", [], $owner))->json('code');
        $deviceToken = self::ok($test->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => "Tablet {$upper}"]))->json('token');

        // Concept note 7.1: the till pushes an M-Pesa payment and confirms one
        // by its code; money arrives on the Paybill that matches nothing yet
        // (C2B, through the method's callback URL).
        // The cashier must be staff of the till's location (the POS module's sign-in permission).
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('pos'));
        $till = ['Authorization' => 'Bearer '.$deviceToken, 'Accept' => 'application/json'];
        self::ok($test->postJson('/api/v1/payments/intents', [
            'payment_method_id' => $paymentMethod, 'mode' => 'stk', 'amount_minor' => '150000', 'currency' => 'KES',
            'phone' => $key === 'a' ? '0712000101' : '0712000201', 'reference_type' => 'pos.sale', 'reference' => (string) Str::uuid7(), 'user_id' => $ownerId,
        ], $till), 201);
        $paymentIntent = self::ok($test->postJson('/api/v1/payments/intents', [
            'payment_method_id' => $paymentMethod, 'mode' => 'manual', 'amount_minor' => '50000', 'currency' => 'KES',
            'receipt' => "QJK3{$upper}MANUAL", 'reference_type' => 'pos.sale', 'reference' => (string) Str::uuid7(), 'user_id' => $ownerId,
        ], $till), 201)->json('data.id');
        $callbackUrls = self::ok($test->getJson("/api/v1/payment-methods/{$paymentMethod}/callbacks", $owner))->json('data.urls');
        $callbackToken = basename(dirname($callbackUrls['c2b-confirm']));
        self::ok($test->postJson("/api/v1/payments/callbacks/{$callbackToken}/c2b-confirm", [
            'TransactionType' => 'Pay Bill', 'TransID' => "QJK3{$upper}LOOSE1", 'TransTime' => now()->setTimezone('Africa/Nairobi')->format('YmdHis'), 'TransAmount' => '700.00',
            'BusinessShortCode' => "17437{$key}", 'BillRefNumber' => "Counter {$upper}", 'MSISDN' => '2547 ***** 126', 'FirstName' => 'Jane',
        ]));
        $paymentReceipt = self::ok($test->getJson("/api/v1/companies/{$company}/payment-receipts", $owner))->json('data.0.id');

        // Concept note 7.2: the company's VAT code carries its eTIMS band,
        // transmission is on (fake driver) and a sale went to the authority.
        self::ok($test->patchJson("/api/v1/tax-codes/{$taxCode}", ['fiscal_code' => 'B'], $owner));
        self::ok($test->putJson("/api/v1/companies/{$company}/fiscal-settings", ['driver' => 'fake', 'tin' => "P05100000{$upper}", 'enabled' => true], $owner), 201);
        $fiscalSale = (string) Str::uuid7();
        TestFiscalSource::sale($fiscalSale, $company, [["Article {$upper}", $taxCode, '12.5', 22500, 2500]]);
        $fiscalSubmission = app(TenantContext::class)->run($tenantId, fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $fiscalSale, $company)->id);

        // RBAC-02: a custom role; the system roles come from sign-up.
        $role = self::ok($test->postJson('/api/v1/roles', [
            'name' => "Clerk {$upper}",
            'permissions' => ['core.location.view', 'core.device.view'],
        ], $owner), 201)->json('data.id');
        $roles = collect(self::ok($test->getJson('/api/v1/roles', $owner))->json('data'))->pluck('id', 'template_key');

        // AUTH-05: a branch manager who accepted, and an invitation still pending.
        $managerEmail = "manager-{$key}@example.com";
        self::ok($test->postJson('/api/v1/invitations', [
            'name' => "Manager {$upper}",
            'email' => $managerEmail,
            'assignments' => [['role_id' => $roles['branch_manager'], 'scope_type' => 'branch', 'scope_id' => $branch]],
        ], $owner), 201);
        $accepted = self::ok($test->postJson('/api/v1/auth/invitations/'.SentInvitations::lastToken($managerEmail).'/accept', [
            'name' => "Manager {$upper}",
            'password' => self::PASSWORD,
        ]), 201);
        $managerId = $accepted->json('user.id');

        $inviteePhone = $key === 'a' ? '+254700000102' : '+254700000202';
        $invitation = self::ok($test->postJson('/api/v1/invitations', [
            'name' => "Cashier {$upper}",
            'phone' => $inviteePhone,
            'assignments' => [['role_id' => $roles['cashier'], 'scope_type' => 'location', 'scope_id' => $location]],
        ], $owner), 201)->json('data.id');

        // RBAC-04: the custom role granted to the manager at the outlet.
        $assignment = self::ok($test->postJson("/api/v1/users/{$managerId}/assignments", [
            'role_id' => $role,
            'scope_type' => 'location',
            'scope_id' => $location,
        ], $owner), 201)->json('data.id');

        // RBAC-05, RBAC-06, RBAC-08: no API in Sprint 1.
        app(TenantContext::class)->run($tenantId, function () use ($role) {
            FieldRule::create(['role_id' => $role, 'resource' => 'product', 'field' => 'cost', 'mode' => 'hidden']);
            LimitRule::create(['role_id' => $role, 'key' => 'max_discount_percent', 'value' => '5']);
            app(ModuleRegistry::class)->deactivate(self::MODULE);
        });

        // NOT-03, NOT-04, NOT-05: the tenant's own text, a mandatory channel,
        // and the owner's preferences (SMS on, email in a daily digest).
        $notificationEvent = NotificationsServiceProvider::TEST_EVENT;
        self::ok($test->putJson('/api/v1/notification-templates', [
            'event_type' => $notificationEvent, 'channel' => 'all',
            'subject' => "Isolation {$upper} from {sender_name}", 'body' => 'Note: {message}',
        ], $owner));
        self::ok($test->putJson('/api/v1/notification-settings', [
            'event_types' => [['event_type' => $notificationEvent, 'mandatory_channels' => ['in_app']]],
        ], $owner));
        self::ok($test->putJson('/api/v1/me/notification-preferences', [
            'preferences' => [['event_type' => $notificationEvent, 'channels' => ['sms' => true], 'digest' => 'daily']],
        ], $owner));

        // NOT-01, NOT-02, NOT-06: a notification to the owner and the manager
        // (inbox rows and deliveries on each channel), sent as a module would.
        $notification = app(TenantContext::class)->run($tenantId, function () use ($notificationEvent, $ownerId, $managerId, $upper) {
            app(Notifier::class)->send(new NotificationEvent($notificationEvent, [$ownerId, $managerId], [
                'sender_name' => "Owner {$upper}", 'message' => "Stock count {$upper}",
            ], '/notifications'));

            return InAppNotification::where('user_id', $ownerId)->value('id');
        });

        // AUTO-01, AUTO-05: a rule on the company's requests, switched on, and
        // its run for the document (raised as the module would on creating it).
        $rule = self::ok($test->postJson('/api/v1/automation-rules', [
            'name' => "Rule {$upper}",
            'document_type' => TestRequestType::KEY,
            'company_id' => $company,
            'trigger' => ['type' => 'record_created'],
            'conditions' => ['field' => 'note', 'op' => 'not_empty'],
            'actions' => [
                ['type' => 'notify', 'to' => ["user:{$ownerId}"], 'subject' => 'Automation {note}', 'message' => 'Created {total}.'],
                ['type' => 'webhook', 'url' => "https://hooks.example.com/{$key}?token=hook-token-{$key}"],
            ],
            'enabled' => true,
        ], $owner), 201)->json('data.id');
        // AUTO-03: a generated webhook signing secret (returned once); B's must never reach A.
        $webhookSecret = self::ok($test->postJson("/api/v1/automation-rules/{$rule}/webhook-secret/rotate", [], $owner))->json('data.webhook_secret');
        $automationRun = app(TenantContext::class)->run($tenantId, function () use ($tenantId, $document, $rule) {
            RecordChanged::dispatch($tenantId, TestRequestType::KEY, $document, RecordChanged::CREATED, [], TestDocuments::find(TestRequestType::KEY, $document)['values']);

            return AutomationRun::query()->where('rule_id', $rule)->where('outcome', 'succeeded')->sole()->id;
        });

        // APR-01..APR-08: the flow for every company has an approval by the
        // manager; a document of the sign-up company (no flow of its own) waits
        // there (an email with single-use links went to the manager), the
        // manager delegated to the owner, who attached a file as their delegate.
        $everyCompany = self::ok($test->postJson('/api/v1/workflows', ['document_type' => TestRequestType::KEY, 'company_id' => null], $owner), 201)->json('data.id');
        self::ok($test->putJson("/api/v1/workflows/{$everyCompany}/draft", ['graph' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'approve', 'type' => 'approval', 'name' => "Approve {$upper}", 'approval' => ['approver' => ['type' => 'user', 'user_id' => $managerId]]],
                ['id' => 'end', 'type' => 'end', 'outcome' => 'approved'],
                ['id' => 'refused', 'type' => 'end', 'outcome' => 'rejected'],
            ],
            'edges' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end'], ['from' => 'approve', 'to' => 'refused', 'branch' => 'rejected']],
        ]], $owner));
        self::ok($test->postJson("/api/v1/workflows/{$everyCompany}/publish", [], $owner));
        $approval = app(TenantContext::class)->run($tenantId, function () use ($signUpCompany, $upper) {
            $id = TestDocuments::create(TestRequestType::KEY, ['total' => ['amount_minor' => '50000', 'currency' => 'KES'], 'note' => "Approval {$upper}"], new DocumentScope($signUpCompany));
            $workflow = app(WorkflowEngine::class)->start(TestRequestType::KEY, $id, null);

            return ApprovalRequest::query()->where('workflow_id', $workflow->id)->value('id');
        });
        $managerToken = ['Authorization' => 'Bearer '.$accepted->json('token')];
        $delegation = self::ok($test->postJson('/api/v1/me/delegations', [
            'to_user_id' => $ownerId, 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(30)->toDateString(),
        ], $managerToken), 201)->json('data.id');
        self::ok($test->post("/api/v1/approvals/{$approval}/attachments", ['file' => UploadedFile::fake()->create("quote-{$key}.pdf", 4, 'application/pdf')], [...$owner, 'Accept' => 'application/json']), 201);

        // MD-01, WF-01: a credit limit change for the shared customer, waiting
        // for the Accountant (its flow adopted from the type's default).
        $creditLimitChange = self::ok($test->postJson('/api/v1/credit-limit-changes', [
            'party_id' => $customer, 'company_id' => $company,
            'requested_limit' => ['amount_minor' => '5000000', 'currency' => 'KES'], 'reason' => "Season {$upper}",
        ], $owner), 201)->json('data.id');

        // AUTH-06..AUTH-08, NFR-04: the till module on (the suite registers it),
        // the owner's POS PIN, a wrong PIN the device reported for the manager,
        // a manager override used for a sale, and a customer who is now only a
        // contact (a sync tombstone). The device pulls everything once.
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('pos'));
        self::ok($test->putJson('/api/v1/me/pos-pin', ['password' => self::PASSWORD, 'pin' => self::PIN], $owner));
        $till = ['Authorization' => 'Bearer '.$deviceToken, 'Accept' => 'application/json'];
        self::ok($test->postJson('/api/v1/pos/pin/attempts', ['reports' => [['user_id' => $managerId, 'failed_attempts' => 1, 'locked' => false]]], $till));
        $overrideToken = self::ok($test->postJson('/api/v1/pos/override', [
            'manager_user_id' => $ownerId, 'pin' => self::PIN, 'permission' => 'pos.sale.void', 'cashier_user_id' => $managerId, 'reference' => "sale-{$key}",
        ], $till))->json('data.token');
        app(TenantContext::class)->run($tenantId, fn () => app(OverrideVerifier::class)->redeem(Device::findOrFail($device), ['token' => $overrideToken], 'pos.sale.void', "sale-{$key}"));
        // AUTH-07: the owner's till sign-in checked online, recorded by its session id (till_sign_ins).
        self::ok($test->postJson('/api/v1/pos/pin/verify', ['user_id' => $ownerId, 'pin' => self::PIN, 'session_id' => (string) Str::uuid7(), 'signed_in_at' => now()->toIso8601String()], $till));
        $formerCustomer = self::ok($test->postJson('/api/v1/parties', ['kind' => 'person', 'name' => "Former customer {$upper}", 'roles' => ['customer']], $owner), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/parties/{$formerCustomer}", ['roles' => ['contact']], $owner));
        self::ok($test->getJson('/api/v1/sync/pull', $till));

        // POS (docs/modules/pos.md), NUM-01, NUM-02: the module switched on (no
        // API yet), then the till as it works: receipt and refund ranges (the
        // tenant's number formats seeded on first use), a shift with a float,
        // three M-Pesa sales by the owner (one voided, one partly refunded,
        // one left for the isolation suite to void) and a cash pay-in.
        $pos = self::pos($test, $owner, $tenantId, $ownerId, $deviceToken, $item, $uoms['EA'], $priceList, $paymentMethod, $taxCode);
        // Accounting (docs/modules/accounting.md): every acc_* table in both tenants.
        $accounting = self::accounting($test, $owner, $tenantId, $company, $customer, $party, $taxCode, $dimensions, $upper);

        // Loyalty (docs/modules/loyalty.md): the module switched on, the
        // programme, a promotion with a coupon, a gift card, a credit
        // account with a charge and a statement, a points adjustment, and a
        // till sale for the customer with the promotion and the card (so
        // every loyalty table has rows, a notice included).
        $loyalty = self::loyalty($test, $owner, $tenantId, $company, $customer, $deviceToken, $pos, $ownerId, $item, $uoms['EA'], $priceList, $taxCode, $taxCategory, $key);

        // EXP-02: a full export request. Not built here: a build reads every
        // table inside the test's transaction, and its locks stay until the
        // test ends (the isolation suite builds one itself). Marked failed so
        // the suite may request another.
        $bus = Bus::getFacadeRoot();
        Bus::fake([BuildTenantExport::class]);
        $tenantExport = self::ok($test->postJson('/api/v1/tenant-exports', ['password' => self::PASSWORD], $owner), 202)->json('data.id');
        Bus::swap($bus);
        app(TenantContext::class)->run($tenantId, fn () => TenantExport::query()->whereKey($tenantExport)->update(['status' => TenantExport::FAILED, 'failure' => 'build_failed']));

        // SUB-02..SUB-04: a subscription converted from its trial, its first
        // invoice (issued by the operator, copied here), a saved M-Pesa
        // wallet and a payment of the invoice waiting for the phone.
        app(TenantContext::class)->run($tenantId, fn () => app(Subscriptions::class)->startTrial('KE', ['modules' => ['pos', 'inventory', 'loyalty', 'accounting_pro'], 'quantities' => ['outlet' => 1], 'cycle' => 'monthly', 'bundle' => 'starter']));
        $firstInvoice = self::ok($test->postJson('/api/v1/subscription/subscribe', [], $owner), 201)->json('data.id');
        $billingMethod = self::ok($test->postJson('/api/v1/subscription/payment-methods', ['kind' => 'mobile_money', 'provider' => 'mpesa_ke', 'phone' => '0712345'.($key === 'a' ? '601' : '602')], $owner), 201)->json('data.id');
        $billingPayment = self::ok($test->postJson("/api/v1/subscription/invoices/{$firstInvoice}/pay", ['payment_method_id' => $billingMethod], $owner), 201)->json('data.id');
        // An upgrade in the period: its own invoice, open with no payment (the routes' invoice).
        $upgrade = ['modules' => ['accounting_pro', 'inventory', 'loyalty', 'payroll', 'pos'], 'quantities' => ['employee' => 20, 'outlet' => 1], 'cycle' => 'monthly', 'bundle' => 'starter'];
        $charge = self::ok($test->postJson('/api/v1/subscription/quote', $upgrade, $owner))->json('data.charge_now');
        $billingInvoice = self::ok($test->postJson('/api/v1/subscription/change', [...$upgrade, 'expected_charge' => $charge], $owner))->json('data.invoice.id');

        // Inventory (docs/modules/inventory.md): switched on by the trial above;
        // a store, FIFO costing, a batch item, a serial item and a variant,
        // opening stock, a reorder level, a pending transfer and adjustment and
        // a running count.
        $inventory = self::inventory($test, $owner, $upper, $branch, $location, $company, $item, $uoms['EA']);
        // Procurement (docs/modules/procurement.md): every procurement_* table in both tenants,
        // through the back office and the supplier portal.
        $procurement = self::procurement($test, $owner, $managerToken, $tenantId, $ownerId, $managerId, $company, $branch, $location,
            $party, $item, $uoms['BOX'], $taxCode, $dimensions, $upper);
        // Stores (docs/modules/stores.md): the module switched on; a requisition
        // of the department to Inventory's store, approved by the branch
        // manager (the department head is its requester), partly issued and
        // partly returned through Inventory.
        $stores = self::stores($test, $owner, ['Authorization' => 'Bearer '.$accepted->json('token'), 'Accept' => 'application/json'], $tenantId, $inventory['inventory_location'], $item, $dimensions);
        // Manufacturing (docs/modules/manufacturing.md): the module switched on; the
        // company's rates; a production BOM (published, with a draft open) of a product
        // from the item with a by-product; a recipe of a menu item; an order released,
        // its item issued, partly returned and partly reported (costed through
        // Inventory); a POS sale of the menu item backflushed.
        $manufacturing = self::manufacturing($test, $owner, $tenantId, $ownerId, $company, $location, $item, $uoms['EA'], $deviceToken, $pos,
            $priceList, $paymentMethod, $taxCode, $taxCategory, $upper);
        // Pharmacy (docs/modules/pharmacy.md): the module switched on; settings, a controlled item with its
        // profile, a received lot, an expired lot with stock (quarantined and announced by the nightly sweep), a
        // pending write-off, a prescription with its image, a recall and a till sale dispensed against the
        // prescription (a dispense, register entries and lot movements).
        $pharmacy = self::pharmacy($test, $owner, $tenantId, $ownerId, $company, $location, $deviceToken, $pos, $priceList, $paymentMethod, $taxCode, $taxCategory, $uoms['EA'], $customer, $upper);
        // HR (docs/modules/hr.md): the module switched on; a position, a default
        // onboarding template, an employee linked to the Owner (self-service)
        // and a peer reporting to them, with identifiers, a pay account, a
        // contract, a clock-in PIN, leave, shifts, a roster and a swap, a
        // punch, a period, an attendance device, a document, a disciplinary
        // case and an open appraisal cycle.
        $hr = self::hr($test, [...$owner, 'Accept' => 'application/json'], $tenantId, $ownerId, $company, $branch, $location, $dimensions, $upper);
        // Fixed assets (docs/modules/fixed-assets.md): the module switched on; settings, a
        // category, a flagged item, two active assets, a posted run, an impairment, a reading,
        // a document, a schedule and a work order, a pending transfer and a pending disposal.
        $fixedAssets = self::fixedAssets($test, [...$owner, 'Accept' => 'application/json'], $tenantId, $ownerId, $company, $location, $dimensions, $party, $uoms['EA'], $upper);
        // CRM (docs/modules/crm.md): the module switched on; settings, a team, a rule, a public
        // lead form and its lead, leads (one converted), opportunities, a draft quote, an activity,
        // a logged email and a target.
        $crm = self::crm($test, $owner, $tenantId, $ownerId, $managerId, $company, $branch, $customer, $item, $uoms['BOX'], $upper);
        // E-commerce (docs/modules/ecommerce.md): the module switched on; a live store with a product and a
        // zone, a WhatsApp chat, a signed-in customer, the Owner as rider, orders delivered (one cashed up)
        // and one accepted and assigned.
        $ecommerce = self::ecommerce($test, $owner, $tenantId, $ownerId, $company, $branch, $location, $item, $uoms['EA'], $priceList, $taxCode, $paymentMethod, $key, $upper);
        // Restaurant (docs/modules/restaurant.md): the module switched on; an area with tables, a station
        // routing a category, a modifier group, the service charge settings, an order of a table sent to the
        // kitchen (tickets, a re-send) and a sale that settled part of it.
        $restaurant = self::restaurant($test, $owner, $tenantId, $ownerId, $company, $location, $item, $uoms['EA'], $deviceToken, $taxCode, $upper);
        // Payroll (docs/modules/payroll.md): the module switched on with the fictional
        // test pack (never the shipped one), settings, a pay group, items, the HR
        // employees copied with their pay setup, an item, a loan, unpaid leave and a
        // closed attendance period from HR, a run prepared by the manager (made a
        // Payroll Officer), approved by the Owner and posted (lines, payslips, loan
        // repayment), and a PAYE return.
        $payroll = self::payroll($test, [...$owner, 'Accept' => 'application/json'], [...$managerToken, 'Accept' => 'application/json'], $tenantId, $managerId, $company, $hr, $upper);
        // IMP-01..IMP-03: one customer imported from a file (an import, its
        // row and the remembered mapping), with its result file.
        $csv = tempnam(sys_get_temp_dir(), 'two-tenants-import-');
        file_put_contents($csv, "Name,Tax ID\nImported {$upper},IMP{$upper}\n");
        $import = self::ok($test->post('/api/v1/imports', ['importer' => 'customers', 'file' => new UploadedFile($csv, "customers-{$key}.csv", 'text/csv', null, true)], [...$owner, 'Accept' => 'application/json']), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/imports/{$import}", ['mode' => 'create'], $owner));
        self::ok($test->postJson("/api/v1/imports/{$import}/run", [], $owner), 202);

        // API-01..API-03 (ADR 020): an API key with the Admin role, used once to list
        // locations (a usage row) and once to create a customer with an Idempotency-Key
        // (a stored response). B's plain key must never reach A.
        $apiKey = self::ok($test->postJson('/api/v1/api-keys', [
            'name' => "Integration {$upper}", 'role_id' => $roles['admin'], 'scope_type' => 'tenant',
            'scopes' => ['core.location.read', 'core.party.write'],
        ], $owner), 201);
        $keyHeaders = ['Authorization' => 'Bearer '.$apiKey->json('secret'), 'Accept' => 'application/json'];
        self::ok($test->getJson('/api/v1/locations', $keyHeaders));
        // API-03: counted in the cache, written to api_key_usage_days by the scheduled flush.
        $test->artisan('api:flush-usage')->assertSuccessful();
        // API-05: an outbox message (the fixture tenants are live: written as a sandbox's outbox would).
        app(TenantContext::class)->run($tenantId, fn () => app(SandboxOutbox::class)->record('email', "outbox-{$key}@example.com", "Outbox {$upper}", "Kept {$upper}", 'mail'));
        self::ok($test->postJson('/api/v1/parties', ['kind' => 'person', 'name' => "API customer {$upper}", 'roles' => ['customer']], [...$keyHeaders, 'Idempotency-Key' => "isolation-{$key}"]), 201);
        // HOOK-01: a webhook subscription of the company (its URL carries a token, its
        // secret is shown once: neither may reach the other tenant) and a test delivery.
        $webhook = self::ok($test->postJson('/api/v1/webhooks', [
            'url' => "https://hooks.example.com/{$key}/path-token-{$key}/events?token=hook-token-{$key}",
            'description' => "Webhook {$upper}",
            'events' => ['sale.completed.v1', 'document.stage_changed.v1'],
            'company_id' => $company,
        ], $owner), 201);
        $subscriptionSecret = $webhook->json('data.secret');
        $webhookDelivery = self::ok($test->postJson("/api/v1/webhooks/{$webhook->json('data.id')}/test", [], $owner), 202)->json('data.id');

        // NFR-08: a data subject access request about the shared customer, and a
        // marketing consent record for it.
        $privacyRequest = self::ok($test->postJson('/api/v1/privacy/requests', [
            'subject_type' => 'customer', 'subject_id' => $customer, 'kind' => 'access', 'note' => "Privacy {$upper}",
        ], $owner), 201)->json('data.id');
        // Approved by the manager (four eyes) and carried out, so its access report exists.
        app(TenantContext::class)->run($tenantId, function () use ($privacyRequest, $managerId, $ownerId) {
            $requests = app(PrivacyRequests::class);
            $request = PrivacyRequest::query()->findOrFail($privacyRequest);
            $requests->approve($request, User::query()->findOrFail($managerId));
            $requests->execute($request, User::query()->findOrFail($ownerId));
        });
        self::ok($test->postJson('/api/v1/privacy/consents', [
            'subject_type' => 'customer', 'subject_id' => $customer, 'purpose' => 'marketing', 'channel' => 'email', 'granted' => true, 'evidence' => "Consent {$upper}",
        ], $owner), 201);
        // MKT-01, MKT-02 (ADR 024): a developer account with a draft app (its secrets are
        // shown once: B's must never reach A), and the shared listed app installed as
        // the fixture role at the location, its code exchanged for tokens.
        self::ok($test->putJson('/api/v1/developer', ['name' => "Developer {$upper}", 'email' => "developer-{$key}@example.com", 'accept_terms' => true], $owner));
        $developerApp = self::ok($test->postJson('/api/v1/developer/apps', [
            'slug' => "isolation-app-{$key}", 'name' => "Developer app {$upper}", 'description' => 'An app of the isolation fixture, never listed, never seen by the other tenant.',
            'category' => 'other', 'redirect_uris' => ["https://developer-{$key}.example.com/cb"], 'scopes' => ['core.item.read'], 'pricing' => ['model' => 'free'],
        ], $owner), 201);
        // Its draft's logo (served to its developer only).
        self::ok($test->post("/api/v1/developer/apps/{$developerApp->json('data.id')}/logo", ['logo' => UploadedFile::fake()->image("logo-{$key}.png", 128, 128)], [...$owner, 'Accept' => 'application/json']));
        $installed = self::ok($test->postJson('/api/v1/oauth/authorize', [
            'client_id' => self::MARKETPLACE_CLIENT, 'redirect_uri' => self::MARKETPLACE_REDIRECT, 'response_type' => 'code', 'state' => "state-{$key}",
            'code_challenge' => self::PKCE_CHALLENGE, 'code_challenge_method' => 'S256',
            'decision' => 'approve', 'role_id' => $role, 'scope_type' => 'location', 'scope_id' => $location,
        ], $owner));
        parse_str((string) parse_url($installed->json('data.redirect_to'), PHP_URL_QUERY), $redirect);
        $appTokens = self::ok($test->post('/api/v1/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => self::MARKETPLACE_CLIENT, 'client_secret' => self::MARKETPLACE_SECRET,
            'redirect_uri' => self::MARKETPLACE_REDIRECT, 'code' => $redirect['code'], 'code_verifier' => self::PKCE_VERIFIER,
        ], ['Accept' => 'application/json']));

        // LIM-01, LIM-02: a day's usage metered against a plan of no devices: the paired till is over,
        // so the tenant has meter rows and an open overage (the Owner is warned).
        $plans = app(PlanAllowances::class);
        $fixed = new FixedAllowances;
        $fixed->set($tenantId, new Allowances([Units::DEVICE => 0]));
        app()->instance(PlanAllowances::class, $fixed);
        app(TenantContext::class)->run($tenantId, fn () => app(UsageLimits::class)->meter(CarbonImmutable::now()));
        app()->instance(PlanAllowances::class, $plans);

        // RBAC-13: a pending support access request from a (fictional) staff member,
        // and ADM-02: a pending Enterprise deal. Tenant rows only: the platform mirror
        // and staff live on the platform connection.
        [$supportGrant, $billingDeal] = app(TenantContext::class)->run($tenantId, fn () => [
            SupportAccessGrant::query()->create(['platform_user_id' => (string) Str::uuid7(), 'staff_name' => 'Support '.$upper, 'staff_email' => "support-{$key}@example.com",
                'reason' => 'Help with the import', 'mode' => 'read', 'hours' => 4, 'requested_at' => now()])->id,
            BillingDeal::query()->create(['country' => 'KE', 'currency' => 'KES', 'lines' => [['type' => 'bundle', 'key' => 'starter', 'amount' => 300000]],
                'effective_from' => now()->toDateString(), 'reason' => 'Negotiated three-year term', 'created_by_staff' => (string) Str::uuid7(), 'created_by_name' => 'Sales '.$upper])->id,
        ]);

        // The owner's sign-up session (a global, non-RLS row).
        $session = PersonalAccessToken::where('tokenable_id', $ownerId)->orderBy('created_at')->value('id');

        return new TenantFixture(
            tenantId: $tenantId,
            ids: [
                'tenant' => $tenantId,
                'company' => $company,
                'sign_up_company' => $signUpCompany,
                'branch' => $branch,
                'location' => $location,
                'archived_location' => $archived,
                'device' => $device,
                'user' => $ownerId,
                'manager' => $managerId,
                'role' => $role,
                'owner_role' => $roles['owner'],
                'tenant_export' => $tenantExport,
                'invitation' => $invitation,
                'assignment' => $assignment,
                'session' => $session,
                'tenant_currency' => $tenantCurrency,
                'tax_code' => $taxCode,
                'tax_category' => $taxCategory,
                'price_list' => $priceList,
                'party' => $party,
                'customer' => $customer,
                'uom' => $uoms['EA'],
                'uom_box' => $uoms['BOX'],
                'item_category' => $itemCategory,
                'item_category_parent' => $parentCategory,
                'item' => $item,
                'item_image' => $itemImage,
                'item_price' => $itemPrice,
                'custom_field' => $customField,
                'custom_field_file' => $customFile,
                'custom_form_type' => $formType,
                'custom_form_record' => $formRecord,
                'payment_method' => $paymentMethod,
                'workflow' => $workflow,
                'workflow_version' => $workflowVersion,
                'document' => $document,
                'notification' => $notification,
                'automation_rule' => $rule,
                'automation_run' => $automationRun,
                'approval' => $approval,
                'delegation' => $delegation,
                'credit_limit_change' => $creditLimitChange,
                'payment_intent' => $paymentIntent,
                'payment_receipt' => $paymentReceipt,
                'fiscal_submission' => $fiscalSubmission,
                'config_document' => $configDocument,
                'template_preview' => $templatePreview,
                'tenant_domain' => $tenantDomain,
                'sso_domain' => $ssoDomain,
                'sso_connection' => $ssoConnection,
                'sso_identity' => $ssoIdentity,
                'sso_live_connection' => $ssoLive,
                'brand_asset' => $brandAsset,
                'billing_invoice' => $billingInvoice,
                'billing_payment' => $billingPayment,
                'billing_payment_method' => $billingMethod,
                'support_access_grant' => $supportGrant,
                'billing_deal' => $billingDeal,
                'import' => $import,
                'api_key' => $apiKey->json('data.id'),
                'developer_app' => $developerApp->json('data.id'),
                'app_installation' => $installed->json('data.installation_id'),
                'webhook' => $webhook->json('data.id'),
                'webhook_delivery' => $webhookDelivery,
                'privacy_request' => $privacyRequest,
                ...$dimensions,
                ...$pos,
                ...$inventory,
                ...$procurement,
                ...$stores,
                ...$manufacturing,
                ...$pharmacy,
                ...$hr,
                ...$fixedAssets,
                ...$crm,
                ...$ecommerce['ids'],
                ...$restaurant,
                ...$payroll,
                ...$accounting,
                ...$loyalty,
                'challenge' => $challenge,
            ],
            tokens: ['owner' => $ownerToken, 'manager' => $accepted->json('token'), 'device' => $deviceToken],
            contacts: array_values(array_filter([$login['email'] ?? null, $login['phone'] ?? null, $managerEmail, $inviteePhone, $partyPhone, $webhookSecret, $subscriptionSecret, "hook-token-{$key}", "path-token-{$key}", $callbackToken, $apiKey->json('secret'),
                $developerApp->json('secrets.client_secret'), $developerApp->json('secrets.webhook_secret'), $appTokens->json('access_token'), $appTokens->json('refresh_token'), ...$ecommerce['contacts']])),
        );
    }

    /**
     * The POS fixture of one tenant, made through the device API.
     *
     * @return array<string, string> pos_shift, pos_sale (partly refunded), pos_sale_line, pos_sale_spare (completed, untouched), and the range patterns
     */
    private static function pos(TestCase $test, array $owner, string $tenantId, string $ownerId, string $deviceToken, string $item, string $uom, string $priceList, string $paymentMethod, string $taxCode): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('pos'));
        $device = ['Authorization' => 'Bearer '.$deviceToken, 'Accept' => 'application/json'];
        $id = fn () => (string) Str::uuid7();

        $receiptRange = self::ok($test->postJson('/api/v1/pos/number-ranges', ['document_type' => 'pos.receipt'], $device))->json('data.0');
        $receipts = $receiptRange['pattern'];
        $refunds = self::ok($test->postJson('/api/v1/pos/number-ranges', ['document_type' => 'pos.refund'], $device))->json('data.0.pattern');

        $shift = $id();
        self::ok($test->postJson('/api/v1/pos/shifts', ['shifts' => [[
            'id' => $shift, 'opened_by_id' => $ownerId, 'opened_at' => now()->subHour()->toIso8601String(),
            'opening_float' => [['currency' => 'KES', 'amount_minor' => '500000']],
        ]]], $device))->assertJsonPath('results.0.status', 'stored');

        $sales = [];
        foreach ([1, 2, 3] as $seq) {
            $sales[] = self::posSale($id, $shift, $ownerId, $seq, str_replace('{000001}', sprintf('%06d', $seq), $receipts), $item, $uom, $priceList, $paymentMethod, $taxCode);
        }
        $stored = self::ok($test->postJson('/api/v1/pos/sales', ['sales' => $sales], $device));
        Assert::assertSame(['stored', 'stored', 'stored'], array_column($stored->json('results'), 'status'), 'TwoTenants POS sales: '.$stored->getContent());

        $void = $id();
        $refund = self::posRefund($id, $sales[0], $shift, $ownerId, 1, str_replace('{000001}', '000001', $refunds), $paymentMethod);
        $movement = $id();
        // H2, H3: nothing proves who acted at the till yet, so the void and refund are held for review.
        self::ok($test->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $void, 'sale_id' => $sales[1]['id'], 'voided_by_id' => $ownerId, 'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong item',
        ]]], $device))->assertJsonPath('results.0.status', 'stored');
        self::ok($test->postJson('/api/v1/pos/refunds', ['refunds' => [$refund]], $device))
            ->assertJsonPath('results.0.status', 'stored');
        self::ok($test->postJson('/api/v1/pos/cash-movements', ['movements' => [[
            'id' => $movement, 'shift_id' => $shift, 'user_id' => $ownerId, 'kind' => 'pay_in', 'currency' => 'KES',
            'amount_minor' => '100000', 'reason' => 'Float top-up', 'occurred_at' => now()->toIso8601String(),
        ]]], $device))->assertJsonPath('results.0.status', 'stored');

        // TPL-04: a public link to the first sale's receipt.
        $documentShare = self::ok($test->postJson("/api/v1/pos/sales/{$sales[0]['id']}/share", [], $owner), 201)->json('data.id');

        return [
            'document_share' => $documentShare,
            'pos_shift' => $shift,
            'pos_sale' => $sales[0]['id'],
            'pos_sale_line' => $sales[0]['lines'][0]['id'],
            'pos_sale_spare' => $sales[2]['id'],
            'pos_receipt_pattern' => $receipts,
            'pos_refund_pattern' => $refunds,
            'pos_number_range' => $receiptRange['id'],
            'pos_void' => $void,
            'pos_refund' => $refund['id'],
            'pos_cash_movement' => $movement,
        ];
    }

    /** @return array<string, string> loyalty_promotion, loyalty_coupon, loyalty_account, loyalty_statement, loyalty_gift_card, loyalty_customer, gift_card_item */
    private static function loyalty(TestCase $test, array $owner, string $tenantId, string $company, string $customer, string $deviceToken, array $pos, string $ownerId, string $item, string $uom, string $priceList, string $taxCode, string $taxCategory, string $key): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('loyalty'));
        $device = ['Authorization' => 'Bearer '.$deviceToken, 'Accept' => 'application/json'];
        $id = fn () => (string) Str::uuid7();
        $upper = strtoupper($key);

        // LOY-21: gift cards are sold as a service item (never stock).
        $giftCardItem = self::ok($test->postJson('/api/v1/items', [
            'code' => "GIFT-{$upper}", 'name' => "Gift card item {$upper}", 'type' => 'service', 'base_uom_id' => $uom, 'tax_category_id' => $taxCategory,
        ], $owner), 201)->json('data.id');
        self::ok($test->putJson("/api/v1/companies/{$company}/loyalty-programme", ['active' => true, 'expiry_months' => 12, 'gift_card_low_minor' => '500000', 'gift_card_item_id' => $giftCardItem], $owner));
        $promotion = self::ok($test->postJson('/api/v1/loyalty/promotions', [
            'company_id' => $company, 'name' => "Ten off {$upper}", 'type' => 'percent', 'percent' => '10', 'status' => 'active', 'requires_coupon' => true,
        ], $owner), 201)->json('data.id');
        $coupon = self::ok($test->postJson("/api/v1/loyalty/promotions/{$promotion}/coupons", ['codes' => ["SAVE10{$upper}"], 'max_uses' => 5], $owner), 201)->json('data.0.id');
        $card = self::ok($test->postJson('/api/v1/loyalty/gift-cards', ['company_id' => $company, 'kind' => 'gift_card', 'currency' => 'KES', 'amount_minor' => '200000', 'active' => true], $owner), 201)->json('data.0');
        $account = self::ok($test->postJson('/api/v1/loyalty/accounts', ['party_id' => $customer, 'company_id' => $company], $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/loyalty/accounts/{$account}/charges", ['amount_minor' => '50000', 'reference' => "INV-{$upper}"], $owner), 201);
        $statement = self::ok($test->postJson("/api/v1/loyalty/accounts/{$account}/statements", ['from' => now()->subMonth()->format('Y-m-d'), 'to' => now()->format('Y-m-d')], $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/loyalty/customers/{$customer}/points/adjust", ['company_id' => $company, 'points' => '25', 'reason' => 'Welcome'], $owner), 201);
        // A customer without a credit account (the isolation suite opens one for it).
        $creditCustomer = self::ok($test->postJson('/api/v1/parties', ['kind' => 'person', 'name' => "Credit customer {$upper}", 'roles' => ['customer']], $owner), 201)->json('data.id');
        $voucher = self::ok($test->postJson("/api/v1/companies/{$company}/payment-methods", ['type' => 'voucher', 'name' => "Gift card {$upper}", 'currency' => 'KES', 'active' => true], $owner), 201)->json('data.id');

        // KES 1,125.00 less the coupon's 10 % (KES 112.50), paid by the card; the card falls below its threshold.
        $sale = self::posSale($id, $pos['pos_shift'], $ownerId, 4, str_replace('{000001}', '000004', $pos['pos_receipt_pattern']), $item, $uom, $priceList, $voucher, $taxCode);
        $sale['customer_id'] = $customer;
        $sale['lines'][0] = [...$sale['lines'][0], 'discount_minor' => '11250', 'tax_minor' => '11250', 'total_minor' => '101250',
            'adjustments' => [['source' => 'loyalty.promotion', 'reference' => $promotion, 'amount_minor' => '11250']]];
        $sale['totals'] = ['subtotal_minor' => '112500', 'discount_minor' => '11250', 'tax_minor' => '11250', 'total_minor' => '101250'];
        $sale['payments'][0] = [...$sale['payments'][0], 'amount_minor' => '101250', 'amount_in_sale_minor' => '101250', 'tender' => ['code_hash' => hash('sha256', str_replace('-', '', $card['code']))]];
        $sale['extensions'] = ['loyalty' => ['coupons' => ["SAVE10{$upper}"], 'promotions' => [['id' => $promotion, 'version' => 1]]]];
        Assert::assertSame('stored', self::ok($test->postJson('/api/v1/pos/sales', ['sales' => [$sale]], $device))->json('results.0.status'));

        return [
            'loyalty_promotion' => $promotion,
            'loyalty_coupon' => $coupon,
            'loyalty_account' => $account,
            'loyalty_statement' => $statement,
            'loyalty_gift_card' => $card['id'],
            'loyalty_customer' => $creditCustomer,
            'gift_card_item' => $giftCardItem,
        ];
    }

    /**
     * The Accounting fixture of one tenant (docs/modules/accounting.md),
     * through the API: the KE chart and settings, a posting rule and a
     * source kept as an exception, a manual journal submitted to its flow
     * and a draft kept for edits, a recurring journal, posted invoices, a
     * posted supplier bill, credit notes (one left open), a draft invoice, a
     * receipt partly allocated, a bank account with an imported statement
     * (one line matched, two not), a rule, a budget with a cell, and a
     * revaluation of January (nothing foreign: no journal).
     *
     * @return array<string, string>
     */
    private static function accounting(TestCase $test, array $owner, string $tenantId, string $company, string $customer, string $supplier, string $taxCode, array $dimensions, string $upper): array
    {
        app(TenantContext::class)->run($tenantId, function () {
            app(ModuleRegistry::class)->activate('accounting');
            app(ModuleRegistry::class)->activate('accounting_pro');
        });
        $base = "/api/v1/companies/{$company}/accounting";
        self::ok($test->postJson("{$base}/chart", [], $owner), 201);
        $accounts = collect(self::ok($test->getJson("{$base}/accounts?per_page=200&status=all", $owner))->json('data'))->pluck('id', 'code');
        $line = fn (string $code, ?string $debit, ?string $credit) => array_filter(['account_id' => $accounts[$code], 'debit' => $debit, 'credit' => $credit]);

        $rule = self::ok($test->postJson("{$base}/posting-rules", ['event_type' => 'test.sale', 'lines' => [
            ['field' => 'total', 'side' => 'debit', 'account_id' => $accounts['1110']],
            ['field' => 'total', 'side' => 'credit', 'account_id' => $accounts['4100']],
        ]], $owner), 201)->json('data.id');
        $exception = app(TenantContext::class)->run($tenantId, fn () => app(PostingService::class)->post(new SourceDocument(
            $company, 'test.missing', (string) Str::uuid7(), CarbonImmutable::parse('2026-10-05T10:00:00Z'), 'KES', ['total' => '1000'],
        ))->exception->id);
        // ACC-26: a sale waiting for its location's daily summary (acc_source_queue).
        self::ok($test->putJson("{$base}/settings", ['sales_posting' => 'daily_summary'], $owner));
        app(TenantContext::class)->run($tenantId, fn () => QueuedSource::query()->create([
            'company_id' => $company, 'source_type' => 'pos.sale', 'source_id' => (string) Str::uuid7(), 'location_id' => Location::query()->whereIn('branch_id', Branch::query()->where('company_id', $company)->select('id'))->value('id'),
            'local_date' => '2026-10-05', 'currency' => 'KES', 'payload' => ['amounts' => ['tender.x' => '1000'], 'memo' => "Queued {$upper}"],
        ]));

        $journalBody = ['date' => '2026-10-05', 'memo' => "Rent {$upper}", 'lines' => [
            [...$line('6200', '100.00', null), 'cost_centre_id' => $dimensions['cost_centre'], 'department_id' => $dimensions['department'], 'project_id' => $dimensions['project']],
            $line('1120', null, '100.00'),
        ]];
        $submitted = self::ok($test->postJson("{$base}/journals", $journalBody, $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/accounting/journals/{$submitted}/submit", [], $owner));
        $journal = self::ok($test->postJson("{$base}/journals", $journalBody, $owner), 201)->json('data.id');
        $recurring = self::ok($test->postJson("{$base}/recurring-journals", ['name' => "Rent {$upper}", 'frequency' => 'monthly', 'next_run_on' => '2027-01-01',
            'lines' => [$line('6200', '100.00', null), $line('1120', null, '100.00')]], $owner), 201)->json('data.id');

        $document = fn (string $kind, string $party, string $account, string $price, array $extra = []) => self::ok($test->postJson("{$base}/documents", [
            'kind' => $kind, 'party_id' => $party, 'issue_date' => '2026-10-05',
            'lines' => [['description' => "Goods {$upper}", 'account_id' => $accounts[$account], 'qty' => '1', 'unit_price' => $price, 'tax_code_id' => $taxCode]], ...$extra,
        ], $owner), 201)->json('data.id');
        $post = fn (string $id) => self::ok($test->postJson("/api/v1/accounting/documents/{$id}/post", [], $owner));
        $invoice = $document('customer_invoice', $customer, '4100', '1000.00');
        $post($invoice);
        $openInvoice = $document('customer_invoice', $customer, '4100', '5000.00');
        $post($openInvoice);
        $post($document('supplier_invoice', $supplier, '5200', '400.00'));
        // A credit note settling part of the first invoice.
        $credit = $document('customer_credit', $customer, '4300', '300.00', ['credits_document_id' => $invoice]);
        $post($credit);
        $bank = self::ok($test->postJson("{$base}/bank-accounts", ['name' => "Bank {$upper}", 'kind' => 'bank', 'account_id' => $accounts['1120'], 'currency' => 'KES'], $owner), 201)->json('data.id');
        $payment = self::ok($test->postJson("{$base}/payments", ['kind' => 'receipt', 'party_id' => $customer, 'bank_account_id' => $bank, 'date' => '2026-10-06', 'amount' => '2000.00',
            'reference' => "DEP{$upper}", 'allocations' => [['document_id' => $invoice, 'amount' => '787.50'], ['document_id' => $openInvoice, 'amount' => '100.00']]], $owner), 201)->json('data.id');
        // The first invoice is settled, so this credit note stays open for allocations.
        $openCredit = $document('customer_credit', $customer, '4300', '100.00', ['credits_document_id' => $invoice]);
        $post($openCredit);
        $draftDocument = $document('customer_invoice', $customer, '4100', '10.00');
        $csv = tempnam(sys_get_temp_dir(), 'two-tenants-statement-');
        file_put_contents($csv, "Date,Description,Reference,Amount\n2026-10-06,Deposit {$upper},DEP{$upper},2000.00\n2026-10-07,Charges {$upper},,-15.00\n2026-10-08,Charges two {$upper},,-25.00\n");
        self::ok($test->post("/api/v1/accounting/bank-accounts/{$bank}/statements", [
            'file' => new UploadedFile($csv, "statement-{$upper}.csv", 'text/csv', null, true), 'format' => 'csv',
            'mapping' => ['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount'],
        ], [...$owner, 'Accept' => 'application/json']), 201);
        $view = self::ok($test->getJson("/api/v1/accounting/bank-accounts/{$bank}/reconciliation", $owner))->json('data');
        $deposit = collect($view['statement_lines'])->firstWhere('reference', "DEP{$upper}")['id'];
        self::ok($test->postJson("/api/v1/accounting/bank-statement-lines/{$deposit}/match", ['journal_line_ids' => $view['suggestions'][$deposit]], $owner));
        $fee = collect($view['statement_lines'])->firstWhere('description', "Charges {$upper}")['id'];
        $bankRule = self::ok($test->postJson("{$base}/bank-rules", ['name' => "Charges {$upper}", 'contains' => 'Charges', 'direction' => 'out', 'account_id' => $accounts['6400']], $owner), 201)->json('data.id');

        $budget = self::ok($test->postJson("{$base}/budgets", ['name' => "Plan {$upper}", 'fiscal_year' => 2026], $owner), 201)->json('data.id');
        self::ok($test->putJson("/api/v1/accounting/budgets/{$budget}/cells", ['cells' => [['account_id' => $accounts['6200'], 'cost_centre_id' => $dimensions['cost_centre'], 'month' => 10, 'amount' => '500.00']]], $owner));
        $periods = self::ok($test->getJson("{$base}/periods?year=2026", $owner))->json('data');
        self::ok($test->postJson("/api/v1/accounting/periods/{$periods[0]['id']}/revalue", [], $owner), 201);

        return [
            'acc_account' => $accounts['6200'],
            'acc_header' => $accounts['6000'],
            'acc_free_asset' => $accounts['1210'],
            'acc_posting_rule' => $rule,
            'acc_posting_exception' => $exception,
            'acc_journal' => $journal,
            'acc_recurring_journal' => $recurring,
            'acc_document' => $draftDocument,
            'acc_invoice' => $openInvoice,
            'acc_credit_note' => $openCredit,
            'acc_payment' => $payment,
            'acc_bank_account' => $bank,
            'acc_bank_statement_line' => $fee,
            'acc_bank_rule' => $bankRule,
            'acc_budget' => $budget,
            'acc_period' => $periods[9]['id'],
        ];
    }

    /** A sale of 2 × the item at KES 562.50, tax included at the fixture's test rate (12.5 %), paid by M-Pesa. */
    public static function posSale(callable $id, string $shift, string $cashier, int $seq, string $number, string $item, string $uom, string $priceList, string $paymentMethod, string $taxCode): array
    {
        return [
            'id' => $id(), 'shift_id' => $shift, 'cashier_id' => $cashier, 'customer_id' => null,
            'receipt_seq' => $seq, 'receipt_number' => $number, 'sold_at' => now()->subMinutes(10)->toIso8601String(),
            'currency' => 'KES', 'price_list_id' => $priceList,
            'lines' => [[
                'id' => $id(), 'item_id' => $item, 'uom_id' => $uom, 'qty' => '2', 'unit_price_minor' => '56250', 'list_price_minor' => '56250',
                'price_list_id' => $priceList, 'tax_inclusive' => true, 'discount_minor' => '0', 'tax_code_id' => $taxCode, 'tax_rate' => '12.5000',
                'tax_minor' => '12500', 'total_minor' => '112500',
            ]],
            'totals' => ['subtotal_minor' => '112500', 'discount_minor' => '0', 'tax_minor' => '12500', 'total_minor' => '112500'],
            'payments' => [[
                'id' => $id(), 'payment_method_id' => $paymentMethod, 'currency' => 'KES', 'amount_minor' => '112500', 'amount_in_sale_minor' => '112500',
                'provider_reference' => 'QK'.$seq, 'status' => 'confirmed',
            ]],
            'change' => ['currency' => 'KES', 'amount_minor' => '0'],
        ];
    }

    /** One of the sale's two units refunded by M-Pesa. */
    public static function posRefund(callable $id, array $sale, string $shift, string $cashier, int $seq, string $number, string $paymentMethod): array
    {
        return [
            'id' => $id(), 'sale_id' => $sale['id'], 'shift_id' => $shift, 'cashier_id' => $cashier,
            'receipt_seq' => $seq, 'receipt_number' => $number, 'refunded_at' => now()->toIso8601String(), 'reason' => 'Damaged',
            'total_minor' => '56250',
            'lines' => [['id' => $id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => '1']],
            'payments' => [['id' => $id(), 'payment_method_id' => $paymentMethod, 'currency' => 'KES', 'amount_minor' => '56250', 'amount_in_sale_minor' => '56250', 'provider_reference' => 'RF'.$seq]],
        ];
    }

    /**
     * The Procurement fixture of one tenant (docs/modules/procurement.md),
     * made through its API and portal as the owner, the branch manager (the
     * default approver and second opener) and the supplier: the company's
     * tolerances; a draft requisition and an approved one; a draft RFQ and
     * an issued one with a quote; a draft tender, an opened one (a sealed
     * bid with a file, opened by two openers) and an awarded one (scored,
     * awarded, its draft PO); a draft PO and an approved one with a posted
     * GRN and a draft GRN; a matched invoice; a draft landed cost and a
     * posted one (allocations). Tender times are moved into the past through
     * the model, as the clock would.
     *
     * @return array<string, string>
     */
    private static function procurement(TestCase $test, array $owner, array $manager, string $tenantId, string $ownerId, string $managerId, string $company, string $branch, string $location,
        string $supplier, string $item, string $box, string $taxCode, array $dimensions, string $upper): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('procurement'));
        self::ok($test->putJson("/api/v1/procurement/companies/{$company}/settings", ['price_tolerance_percent' => '1', 'qty_tolerance_percent' => '0', 'over_receipt_percent' => '5'], $owner));

        $lines = [['item_id' => $item, 'uom_id' => $box, 'qty' => '2', 'estimated_unit_price_minor' => '120000',
            'cost_centre_id' => $dimensions['cost_centre'], 'project_id' => $dimensions['project']]];
        $requisitionBody = ['branch_id' => $branch, 'location_id' => $location, 'department_id' => $dimensions['department'], 'purpose' => "Restock {$upper}", 'lines' => $lines];
        $requisition = self::ok($test->postJson('/api/v1/procurement/requisitions', $requisitionBody, $owner), 201)->json('data.id');
        $approvedRequisition = self::ok($test->postJson('/api/v1/procurement/requisitions', $requisitionBody, $owner), 201)->json('data');
        self::ok($test->postJson("/api/v1/procurement/requisitions/{$approvedRequisition['id']}/submit", [], $owner));
        $approve = function (string $documentId) use ($test, $tenantId, $manager) {
            $approval = app(TenantContext::class)->run($tenantId, fn () => ApprovalRequest::query()->where('document_id', $documentId)->sole()->id);
            self::ok($test->postJson("/api/v1/approvals/{$approval}/approve", [], $manager));
        };
        $approve($approvedRequisition['id']);

        $rfqBody = ['branch_id' => $branch, 'title' => "Soap RFQ {$upper}", 'deadline_at' => now()->addDays(5)->toIso8601String(),
            'lines' => [['item_id' => $item, 'uom_id' => $box, 'qty' => '2']], 'supplier_ids' => [$supplier]];
        $rfq = self::ok($test->postJson('/api/v1/procurement/rfqs', $rfqBody, $owner), 201)->json('data.id');
        $issued = self::ok($test->postJson('/api/v1/procurement/rfqs', $rfqBody, $owner), 201)->json('data');
        self::ok($test->postJson("/api/v1/procurement/rfqs/{$issued['id']}/issue", [], $owner));
        self::ok($test->postJson('/api/v1/procurement/quotes', ['rfq_id' => $issued['id'], 'party_id' => $supplier, 'quote_date' => now()->toDateString(), 'currency' => 'KES',
            'reference' => "Q-{$upper}", 'lines' => [['rfq_line_id' => $issued['lines'][0]['id'], 'unit_price_minor' => '118000', 'lead_time_days' => 4]]], $owner), 201);

        // Tenders: a draft, then two published ones whose bids are opened.
        $tenderBody = fn (string $title) => [
            'branch_id' => $branch, 'title' => $title, 'kind' => 'invited', 'deadline_at' => now()->addHour()->toIso8601String(), 'opening_at' => now()->addHour()->toIso8601String(),
            'technical_weight' => 50, 'lines' => [['item_id' => $item, 'uom_id' => $box, 'qty' => '2']], 'criteria' => [['name' => "Quality {$upper}", 'weight' => 100, 'max_score' => 10]],
            'openers' => [$ownerId, $managerId], 'evaluators' => [$ownerId],
        ];
        $tender = self::ok($test->postJson('/api/v1/procurement/tenders', $tenderBody("Draft tender {$upper}"), $owner), 201)->json('data.id');
        $openedTender = function (string $title, string $email) use ($test, $owner, $manager, $tenantId, $tenderBody, $supplier, $upper) {
            $tender = self::ok($test->postJson('/api/v1/procurement/tenders', $tenderBody($title), $owner), 201)->json('data');
            self::ok($test->postJson("/api/v1/procurement/tenders/{$tender['id']}/bidders", ['party_id' => $supplier, 'email' => $email], $owner), 201);
            $bidder = self::ok($test->postJson("/api/v1/procurement/tenders/{$tender['id']}/publish", [], $owner))->json('data.bidders.0.id');
            $link = app(TenantContext::class)->run($tenantId, fn () => app(Bidders::class)->issueLinkForTesting(Bidder::query()->findOrFail($bidder)));
            $portal = ['Authorization' => 'Bearer '.self::ok($test->postJson('/api/v1/supplier-portal/sessions', ['token' => $link]), 201)->json('data.session_token')];
            $file = self::ok($test->post('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->createWithContent("plan-{$upper}.txt", "Plan {$upper}")], [...$portal, 'Accept' => 'application/json']), 201)->json('data.id');
            self::ok($test->putJson('/api/v1/supplier-portal/bid', ['currency' => 'KES', 'lines' => [['tender_line_id' => $tender['lines'][0]['id'], 'unit_price_minor' => '115000']],
                'technical_response' => "Response {$upper}", 'file_ids' => [$file]], $portal));
            app(TenantContext::class)->run($tenantId, fn () => Tender::query()->findOrFail($tender['id'])->fill(['deadline_at' => now()->subMinute(), 'opening_at' => now()->subMinute()])->save());
            self::ok($test->postJson("/api/v1/procurement/tenders/{$tender['id']}/open", [], $owner));
            $opened = self::ok($test->postJson("/api/v1/procurement/tenders/{$tender['id']}/open", [], $manager))->json('data');

            return [$opened, $bidder, $file];
        };
        [$opened, $bidder, $bidFile] = $openedTender("Opened tender {$upper}", "bids-{$upper}@supplier.example.com");
        [$awarded] = $openedTender("Awarded tender {$upper}", "award-{$upper}@supplier.example.com");
        self::ok($test->putJson("/api/v1/procurement/bids/{$awarded['bids'][0]['id']}/scores", ['scores' => [['criterion_id' => $awarded['criteria'][0]['id'], 'score' => 8]]], $owner));
        self::ok($test->postJson("/api/v1/procurement/bids/{$awarded['bids'][0]['id']}/award", [], $owner));

        // POs, GRNs, an invoice and landed costs.
        $orderBody = ['branch_id' => $branch, 'party_id' => $supplier, 'location_id' => $location, 'expected_on' => now()->addDays(3)->toDateString(), 'terms' => "Terms {$upper}",
            'lines' => [['item_id' => $item, 'uom_id' => $box, 'qty' => '4', 'unit_price_minor' => '118000', 'tax_code_id' => $taxCode,
                'cost_centre_id' => $dimensions['cost_centre'], 'project_id' => $dimensions['project']]]];
        $order = self::ok($test->postJson('/api/v1/procurement/orders', $orderBody, $owner), 201)->json('data.id');
        $approvedOrder = self::ok($test->postJson('/api/v1/procurement/orders', $orderBody, $owner), 201)->json('data');
        self::ok($test->postJson("/api/v1/procurement/orders/{$approvedOrder['id']}/submit", [], $owner));
        $approve($approvedOrder['id']);
        $orderLine = $approvedOrder['lines'][0]['id'];
        $posted = self::ok($test->postJson('/api/v1/procurement/receipts', ['order_id' => $approvedOrder['id'], 'delivery_note' => "DN-{$upper}",
            'lines' => [['order_line_id' => $orderLine, 'received_qty' => '2', 'weight' => '3']]], $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/procurement/receipts/{$posted}/post", [], $owner));
        $receipt = self::ok($test->postJson('/api/v1/procurement/receipts', ['order_id' => $approvedOrder['id'],
            'lines' => [['order_line_id' => $orderLine, 'received_qty' => '1']]], $owner), 201)->json('data.id');
        $invoice = self::ok($test->postJson('/api/v1/procurement/invoices', ['order_id' => $approvedOrder['id'], 'supplier_reference' => "SINV-{$upper}", 'invoice_date' => now()->toDateString(),
            'lines' => [['order_line_id' => $orderLine, 'qty' => '2']]], $owner), 201)->json('data.id');
        $landedBody = ['branch_id' => $branch, 'cost_date' => now()->toDateString(), 'method' => 'value', 'description' => "Freight {$upper}",
            'charges' => [['description' => "Freight {$upper}", 'party_id' => $supplier, 'bill_reference' => "FB-{$upper}", 'currency' => 'KES', 'amount_minor' => '30000']], 'receipt_ids' => [$posted]];
        $landedCost = self::ok($test->postJson('/api/v1/procurement/landed-costs', $landedBody, $owner), 201)->json('data.id');
        $postedLanded = self::ok($test->postJson('/api/v1/procurement/landed-costs', $landedBody, $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/procurement/landed-costs/{$postedLanded}/post", [], $owner));

        return [
            'procurement_requisition' => $requisition,
            'procurement_requisition_approved' => $approvedRequisition['id'],
            'procurement_requisition_line' => $approvedRequisition['lines'][0]['id'],
            'procurement_rfq' => $rfq,
            'procurement_rfq_issued' => $issued['id'],
            'procurement_rfq_line' => $issued['lines'][0]['id'],
            'procurement_tender' => $tender,
            'procurement_bidder' => $bidder,
            'procurement_bid' => $opened['bids'][0]['id'],
            'procurement_bid_file' => $bidFile,
            'procurement_criterion' => $opened['criteria'][0]['id'],
            'procurement_decided_bid' => $awarded['bids'][0]['id'],
            'procurement_order' => $order,
            'procurement_order_approved' => $approvedOrder['id'],
            'procurement_order_line' => $orderLine,
            'procurement_receipt' => $receipt,
            'procurement_invoice' => $invoice,
            'procurement_landed_cost' => $landedCost,
        ];
    }

    /**
     * The Inventory fixture of one tenant, made through its API.
     *
     * @return array<string, string>
     */
    private static function inventory(TestCase $test, array $owner, string $upper, string $branch, string $location, string $company, string $item, string $each): array
    {
        $store = self::ok($test->postJson("/api/v1/branches/{$branch}/locations", ['name' => "Store {$upper}", 'type' => 'warehouse'], $owner), 201)->json('data.id');
        self::ok($test->putJson("/api/v1/inventory/companies/{$company}/settings", ['costing_method' => 'fifo', 'allow_negative' => true], $owner));

        $newItem = fn (string $code) => self::ok($test->postJson('/api/v1/items', ['code' => "{$code}-{$upper}", 'name' => "{$code} {$upper}", 'type' => 'stock', 'base_uom_id' => $each], $owner), 201)->json('data.id');
        $batchItem = $newItem('INV-BATCH');
        $serialItem = $newItem('INV-SERIAL');
        $variant = $newItem('INV-VARIANT');
        self::ok($test->putJson("/api/v1/inventory/items/{$batchItem}/settings", ['tracking' => 'batch', 'track_expiry' => true], $owner));
        self::ok($test->putJson("/api/v1/inventory/items/{$serialItem}/settings", ['tracking' => 'serial'], $owner));
        self::ok($test->putJson("/api/v1/inventory/items/{$variant}/variant", ['parent_item_id' => $batchItem, 'attributes' => ['size' => 'S']], $owner));

        self::ok($test->postJson('/api/v1/inventory/opening-stock', ['item_id' => $item, 'location_id' => $location, 'qty' => '50', 'unit_cost' => '10'], $owner), 201);
        self::ok($test->postJson('/api/v1/inventory/opening-stock', ['item_id' => $batchItem, 'location_id' => $location, 'qty' => '20', 'unit_cost' => '5', 'batch_number' => "LOT-{$upper}", 'expires_on' => '2027-06-30'], $owner), 201);
        self::ok($test->postJson('/api/v1/inventory/opening-stock', ['item_id' => $serialItem, 'location_id' => $store, 'qty' => '2', 'unit_cost' => '300', 'serials' => ["SN-{$upper}-1", "SN-{$upper}-2"]], $owner), 201);
        $batch = self::ok($test->getJson("/api/v1/inventory/items/{$batchItem}", $owner))->json('data.batches.0.id');
        self::ok($test->putJson('/api/v1/inventory/reorder-rules', ['item_id' => $item, 'location_id' => $location, 'reorder_level' => '5', 'reorder_qty' => '24'], $owner));

        $transfer = self::ok($test->postJson('/api/v1/inventory/transfers', [
            'from_location_id' => $location, 'to_location_id' => $store, 'lines' => [['item_id' => $item, 'qty' => '2']],
        ], $owner), 201)->json('data.id');
        $adjustment = self::ok($test->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $location, 'reason' => 'damage', 'lines' => [['item_id' => $batchItem, 'qty' => '-1', 'batch_id' => $batch]],
        ], $owner), 201)->json('data.id');
        $count = self::ok($test->postJson('/api/v1/inventory/counts', ['location_id' => $store, 'kind' => 'cycle', 'item_ids' => [$serialItem]], $owner), 201)->json('data.id');

        return [
            'inventory_location' => $store,
            'inventory_item' => $batchItem,
            'inventory_serial_item' => $serialItem,
            'inventory_variant' => $variant,
            'inventory_batch' => $batch,
            'inventory_transfer' => $transfer,
            'inventory_adjustment' => $adjustment,
            'inventory_count' => $count,
        ];
    }

    /**
     * The Stores fixture of one tenant, made through its API.
     *
     * @param  array<string, string>  $dimensions
     * @return array<string, string>
     */
    private static function stores(TestCase $test, array $owner, array $manager, string $tenantId, string $store, string $item, array $dimensions): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('stores'));

        $requisition = self::ok($test->postJson('/api/v1/stores/requisitions', [
            'location_id' => $store, 'department_id' => $dimensions['department'], 'cost_centre_id' => $dimensions['cost_centre'],
            'lines' => [['item_id' => $item, 'qty' => '5']],
        ], $owner), 201)->json('data');
        $approval = app(TenantContext::class)->run($tenantId, fn () => ApprovalRequest::query()->where('document_id', $requisition['id'])->value('id'));
        self::ok($test->postJson("/api/v1/approvals/{$approval}/approve", [], $manager));
        $issue = self::ok($test->postJson("/api/v1/stores/requisitions/{$requisition['id']}/issues", [
            'lines' => [['requisition_line_id' => $requisition['lines'][0]['id'], 'qty' => '2']],
        ], $owner), 201)->json('data');
        $return = self::ok($test->postJson("/api/v1/stores/issues/{$issue['id']}/returns", [
            'reason' => 'unused', 'lines' => [['issue_line_id' => $issue['lines'][0]['id'], 'qty' => '1']],
        ], $owner), 201)->json('data.id');

        return [
            'stores_requisition' => $requisition['id'],
            'stores_requisition_line' => $requisition['lines'][0]['id'],
            'stores_issue' => $issue['id'],
            'stores_issue_line' => $issue['lines'][0]['id'],
            'stores_return' => $return,
        ];
    }

    /**
     * The Restaurant fixture of one tenant: every rest_* table has rows. Made through the
     * back office API as the Owner and the paired till's order upload; the settlement is
     * a row of the order's sale (the POS sale path is covered by the module's tests).
     *
     * @return array<string, string>
     */
    private static function restaurant(TestCase $test, array $owner, string $tenantId, string $ownerId, string $company, string $location, string $item, string $each,
        string $deviceToken, string $taxCode, string $upper): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('restaurant'));
        $owner = [...$owner, 'Accept' => 'application/json'];
        $device = ['Authorization' => 'Bearer '.$deviceToken, 'Accept' => 'application/json'];

        $newItem = fn (string $code, string $type, array $extra = []) => self::ok($test->postJson('/api/v1/items', [
            'code' => "{$code}-{$upper}", 'name' => "{$code} {$upper}", 'type' => $type, 'base_uom_id' => $each, ...$extra,
        ], $owner), 201)->json('data.id');
        $service = $newItem('REST-SERVICE', 'non_stock');
        $tip = $newItem('REST-TIP', 'non_stock');
        $category = self::ok($test->postJson('/api/v1/item-categories', ['name' => "Rest category {$upper}"], $owner), 201)->json('data.id');
        $dish = $newItem('REST-DISH', 'non_stock', ['category_id' => $category]);

        self::ok($test->putJson("/api/v1/restaurant/companies/{$company}/settings", ['service_charge_percent' => '10', 'service_charge_item_id' => $service, 'tip_item_id' => $tip, 'tip_presets' => [5, 10]], $owner));
        $area = self::ok($test->postJson('/api/v1/restaurant/areas', ['location_id' => $location, 'name' => "Hall {$upper}"], $owner), 201)->json('data.id');
        $table = self::ok($test->postJson('/api/v1/restaurant/tables', ['location_id' => $location, 'area_id' => $area, 'name' => "T1 {$upper}", 'seats' => 4], $owner), 201)->json('data.id');
        $second = self::ok($test->postJson('/api/v1/restaurant/tables', ['location_id' => $location, 'area_id' => $area, 'name' => "T2 {$upper}", 'seats' => 2], $owner), 201)->json('data.id');
        $station = self::ok($test->postJson('/api/v1/restaurant/stations', ['location_id' => $location, 'name' => "Kitchen {$upper}", 'kind' => 'kitchen', 'is_default' => true, 'category_ids' => [$category]], $owner), 201)->json('data.id');
        $groupResponse = self::ok($test->postJson('/api/v1/restaurant/modifier-groups', [
            'company_id' => $company, 'name' => "Extras {$upper}", 'min_select' => 0, 'max_select' => 2, 'currency' => 'KES', 'item_ids' => [$dish],
            'modifiers' => [['name' => 'Extra shot', 'price_minor' => '5000']],
        ], $owner), 201);
        $group = $groupResponse->json('data.id');
        $modifier = $groupResponse->json('data.modifiers.0.id');

        $line = fn (string $name) => [
            'id' => (string) Str::uuid7(), 'item_id' => $dish, 'name' => $name, 'qty' => '1', 'unit_price_minor' => '80000', 'tax_code_id' => $taxCode, 'tax_rate' => '16', 'tax_inclusive' => false,
            'course' => 1, 'modifiers' => [['id' => $modifier, 'name' => 'Extra shot', 'price_minor' => '5000']], 'created_at' => now()->subMinutes(30)->toIso8601String(),
            'sent_at' => now()->subMinutes(20)->toIso8601String(), 'sent_by' => $ownerId, 'round' => 1,
        ];
        $first = $line("Dish {$upper}");
        $resend = (string) Str::uuid7();
        $result = self::ok($test->postJson('/api/v1/restaurant/orders/sync', ['orders' => [[
            'id' => (string) Str::uuid7(), 'kind' => 'table', 'table_id' => $table, 'waiter_id' => $ownerId, 'covers' => 2, 'currency' => 'KES',
            'opened_at' => now()->subMinutes(40)->toIso8601String(), 'updated_at' => now()->subMinutes(30)->toIso8601String(), 'updated_by' => $ownerId,
            'lines' => [$first, $line("Side {$upper}")],
            'resends' => [['id' => $resend, 'line_ids' => [$first['id']], 'at' => now()->subMinutes(10)->toIso8601String(), 'by' => $ownerId]],
        ]]], $device))->json('results.0');
        Assert::assertSame('stored', $result['status'], 'TwoTenants: the restaurant order was not stored: '.json_encode($result));
        $order = $result['id'];

        $ticket = app(TenantContext::class)->run($tenantId, function () use ($order, $location, $first) {
            Settlement::create([
                'order_id' => $order, 'location_id' => $location, 'sale_id' => (string) Str::uuid7(), 'mode' => 'equal', 'part' => 1, 'parts' => 2, 'final' => false, 'currency' => 'KES',
                'total_minor' => '50000', 'tip_minor' => '0', 'service_charge_minor' => '0', 'line_ids' => [$first['id']], 'settled_at' => now(),
            ]);

            return Ticket::query()->where('order_id', $order)->orderBy('number')->value('id');
        });

        return [
            'rest_area' => $area,
            'rest_table' => $table,
            'rest_table_other' => $second,
            'rest_station' => $station,
            'rest_modifier_group' => $group,
            'rest_order' => $order,
            'rest_order_line' => $first['id'],
            'rest_ticket' => $ticket,
            'rest_item' => $dish,
            'rest_service_item' => $service,
            'rest_category' => $category,
        ];
    }

    /**
     * The Manufacturing fixture of one tenant, made through its API as the
     * Owner (and the till for the sale): every mfg_* table has rows.
     *
     * @param  array<string, string>  $pos
     * @return array<string, string>
     */
    private static function manufacturing(TestCase $test, array $owner, string $tenantId, string $ownerId, string $company, string $location, string $item, string $each,
        string $deviceToken, array $pos, string $priceList, string $paymentMethod, string $taxCode, string $taxCategory, string $upper): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('manufacturing'));
        $owner = [...$owner, 'Accept' => 'application/json'];

        self::ok($test->putJson("/api/v1/manufacturing/companies/{$company}/settings", ['labour_rate_minor' => '60000', 'overhead_rate_minor' => '30000', 'release_approval' => false], $owner));
        $newItem = fn (string $code, string $type, array $extra = []) => self::ok($test->postJson('/api/v1/items', [
            'code' => "{$code}-{$upper}", 'name' => "{$code} {$upper}", 'type' => $type, 'base_uom_id' => $each, ...$extra,
        ], $owner), 201)->json('data.id');
        $product = $newItem('MFG-PRODUCT', 'stock');
        $byProduct = $newItem('MFG-BYPRODUCT', 'stock');
        $menu = $newItem('MFG-MENU', 'kit', ['tax_category_id' => $taxCategory]);

        $bom = self::ok($test->postJson('/api/v1/manufacturing/boms', ['company_id' => $company, 'item_id' => $product, 'kind' => 'production', 'version' => [
            'output_qty' => '1', 'labour_minutes' => 30, 'lines' => [
                ['item_id' => $item, 'qty' => '1', 'wastage_percent' => '5'],
                ['kind' => 'by_product', 'item_id' => $byProduct, 'qty' => '1', 'cost_share_percent' => '10'],
            ],
        ]], $owner), 201)->json('data');
        $published = self::ok($test->postJson("/api/v1/manufacturing/bom-versions/{$bom['draft_id']}/publish", ['effective_from' => now()->subHours(2)->toIso8601String()], $owner))->json('data.id');
        $draft = self::ok($test->postJson("/api/v1/manufacturing/boms/{$bom['id']}/versions", [], $owner), 201)->json('data.id');
        $recipe = self::ok($test->postJson('/api/v1/manufacturing/boms', ['company_id' => $company, 'item_id' => $menu, 'kind' => 'recipe', 'version' => [
            'output_qty' => '1', 'lines' => [['item_id' => $item, 'qty' => '1']],
        ]], $owner), 201)->json('data.draft_id');
        self::ok($test->postJson("/api/v1/manufacturing/bom-versions/{$recipe}/publish", ['effective_from' => now()->subHours(2)->toIso8601String()], $owner));

        $order = self::ok($test->postJson('/api/v1/manufacturing/orders', ['location_id' => $location, 'item_id' => $product, 'qty' => '4'], $owner), 201)->json('data');
        self::ok($test->postJson("/api/v1/manufacturing/orders/{$order['id']}/release", [], $owner));
        $issued = self::ok($test->postJson("/api/v1/manufacturing/orders/{$order['id']}/issues", ['lines' => [['material_id' => $order['materials'][0]['id'], 'qty' => '3']]], $owner), 201)->json('data');
        $issue = $issued['issues'][0];
        self::ok($test->postJson("/api/v1/manufacturing/orders/{$order['id']}/returns", ['issue_id' => $issue['id'], 'reason' => 'unused', 'lines' => [['issue_line_id' => $issue['lines'][0]['id'], 'qty' => '1']]], $owner), 201);
        $output = collect($issued['outputs'])->firstWhere('kind', 'by_product')['id'];
        self::ok($test->postJson("/api/v1/manufacturing/orders/{$order['id']}/reports", ['qty_good' => '1', 'by_products' => [['output_id' => $output, 'qty' => '1']]], $owner), 201);

        $device = ['Authorization' => 'Bearer '.$deviceToken, 'Accept' => 'application/json'];
        $sale = self::posSale(fn () => (string) Str::uuid7(), $pos['pos_shift'], $ownerId, 8, str_replace('{000001}', '000008', $pos['pos_receipt_pattern']), $menu, $each, $priceList, $paymentMethod, $taxCode);
        self::ok($test->postJson('/api/v1/pos/sales', ['sales' => [$sale]], $device))->assertJsonPath('results.0.status', 'stored');
        $backflush = app(TenantContext::class)->run($tenantId, fn () => Backflush::query()->where('source_id', $sale['id'])->value('id'));
        Assert::assertNotNull($backflush, 'TwoTenants: the menu item sale was not backflushed');

        return [
            'mfg_bom' => $bom['id'],
            'mfg_bom_version' => $draft,
            'mfg_bom_version_published' => $published,
            'mfg_product' => $product,
            'mfg_order' => $order['id'],
            'mfg_material' => $order['materials'][0]['id'],
            'mfg_output' => $output,
            'mfg_issue' => $issue['id'],
            'mfg_issue_line' => $issue['lines'][0]['id'],
            'mfg_backflush' => $backflush,
        ];
    }

    /**
     * The Pharmacy fixture of one tenant (docs/modules/pharmacy.md), made
     * through its API as the Owner and the device: see the call in build().
     *
     * @return array<string, string> pharmacy_item, pharmacy_lot, pharmacy_receipt, pharmacy_write_off, pharmacy_recall, pharmacy_prescription, pharmacy_dispense
     */
    private static function pharmacy(TestCase $test, array $owner, string $tenantId, string $ownerId, string $company, string $location, string $deviceToken, array $pos,
        string $priceList, string $paymentMethod, string $taxCode, string $taxCategory, string $each, string $customer, string $upper): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('pharmacy'));
        $owner = [...$owner, 'Accept' => 'application/json'];

        self::ok($test->putJson("/api/v1/companies/{$company}/pharmacy-settings", ['near_expiry_days' => 60, 'prescription_valid_days' => 180], $owner));
        $item = self::ok($test->postJson('/api/v1/items', [
            'code' => "PHARM-{$upper}", 'name' => "Pharmacy item {$upper}", 'type' => 'stock', 'base_uom_id' => $each, 'tax_category_id' => $taxCategory,
        ], $owner), 201)->json('data.id');
        self::ok($test->putJson("/api/v1/pharmacy/items/{$item}/profile", ['class' => 'controlled', 'batch_tracked' => true, 'usage_notes' => "Usage notes {$upper}"], $owner));

        $receipt = self::ok($test->postJson('/api/v1/pharmacy/receipts', ['location_id' => $location, 'reference' => "GRN-{$upper}", 'lines' => [[
            'item_id' => $item, 'qty' => '20', 'batch_number' => "LOT-{$upper}", 'expires_on' => now()->addDays(200)->toDateString(), 'unit_cost_minor' => '1000', 'currency' => 'KES',
        ]]], $owner), 201)->json('data');
        $lot = $receipt['lines'][0]['lot']['id'];

        // An expired lot with stock, as a late delivery or a long shelf leaves it; the nightly sweep quarantines and announces it.
        app(TenantContext::class)->run($tenantId, function () use ($company, $item, $location, $upper) {
            $lots = app(Lots::class);
            $old = $lots->ensure($company, $item, "OLD-{$upper}", now()->subDays(5)->toDateString());
            $lots->move($old, $location, 'receipt', BigDecimal::of('3'), 'fixture', (string) Str::uuid7());
        });
        $test->artisan('pharmacy:expiry-alerts')->assertSuccessful();

        $old = app(TenantContext::class)->run($tenantId, fn () => app(Lots::class)->find($company, $item, "OLD-{$upper}")->id);
        $writeOff = self::ok($test->postJson('/api/v1/pharmacy/write-offs', ['location_id' => $location, 'reason' => 'expired', 'note' => "Shelf clearance {$upper}", 'lines' => [['lot_id' => $old, 'qty' => '1']]], $owner), 201)->json('data.id');
        $prescription = self::ok($test->postJson('/api/v1/pharmacy/prescriptions', [
            'location_id' => $location, 'prescriber_name' => "Dr Prescriber {$upper}", 'prescriber_licence' => "LIC-{$upper}", 'facility' => "Clinic {$upper}",
            'patient_name' => "Patient {$upper}", 'patient_party_id' => $customer, 'prescribed_on' => now()->subDay()->toDateString(),
        ], $owner), 201)->json('data.id');
        self::ok($test->post("/api/v1/pharmacy/prescriptions/{$prescription}/image", ['file' => UploadedFile::fake()->image("rx-{$upper}.jpg")], $owner));
        $recall = self::ok($test->postJson('/api/v1/pharmacy/recalls', ['item_id' => $item, 'batch_number' => "RECALLED-{$upper}", 'reason' => "Recall reason {$upper}", 'reference' => "REF-{$upper}"], $owner), 201)->json('data.id');

        // A till sale of the controlled item against a prescription of the till's own: a dispense, lot movements and register entries.
        $device = ['Authorization' => 'Bearer '.$deviceToken, 'Accept' => 'application/json'];
        $sale = self::posSale(fn () => (string) Str::uuid7(), $pos['pos_shift'], $ownerId, 9, str_replace('{000001}', '000009', $pos['pos_receipt_pattern']), $item, $each, $priceList, $paymentMethod, $taxCode);
        $sale['customer_id'] = $customer;
        $sale['extensions'] = ['pharmacy' => [
            'lines' => [['line_id' => $sale['lines'][0]['id'], 'lots' => [['lot_id' => $lot, 'qty' => '2']]]],
            'prescription' => [
                'id' => (string) Str::uuid7(), 'prescriber_name' => "Dr Till {$upper}", 'prescriber_licence' => "TLIC-{$upper}", 'facility' => "Till clinic {$upper}",
                'patient_name' => "Till patient {$upper}", 'prescribed_on' => now()->subDay()->toDateString(),
            ],
        ]];
        self::ok($test->postJson('/api/v1/pos/sales', ['sales' => [$sale]], $device))->assertJsonPath('results.0.status', 'stored');
        $dispense = app(TenantContext::class)->run($tenantId, fn () => Dispense::query()->where('sale_id', $sale['id'])->value('id'));
        Assert::assertNotNull($dispense, 'TwoTenants: the controlled item sale was not dispensed');

        return [
            'pharmacy_item' => $item,
            'pharmacy_lot' => $lot,
            'pharmacy_receipt' => $receipt['id'],
            'pharmacy_write_off' => $writeOff,
            'pharmacy_recall' => $recall,
            'pharmacy_prescription' => $prescription,
            'pharmacy_dispense' => $dispense,
        ];
    }

    /**
     * The HR fixture of one tenant, made through its API as the Owner.
     *
     * @param  array<string, string>  $dimensions
     * @return array<string, string>
     */
    private static function hr(TestCase $test, array $owner, string $tenantId, string $ownerId, string $company, string $branch, string $location, array $dimensions, string $upper): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('hr'));

        $position = self::ok($test->postJson('/api/v1/hr/positions', ['company_id' => $company, 'code' => "POS-{$upper}", 'title' => "Cashier {$upper}", 'department_id' => $dimensions['department']], $owner), 201)->json('data.id');
        $template = self::ok($test->postJson('/api/v1/hr/checklist-templates', [
            'company_id' => $company, 'kind' => 'onboarding', 'name' => "Starter {$upper}", 'is_default' => true, 'items' => [['title' => "Sign contract {$upper}", 'due_days' => 0]],
        ], $owner), 201)->json('data.id');
        $employee = self::ok($test->postJson('/api/v1/hr/employees', [
            'company_id' => $company, 'first_name' => 'Worker', 'last_name' => $upper, 'hire_date' => '2026-01-05', 'branch_id' => $branch, 'location_id' => $location,
            'department_id' => $dimensions['department'], 'position_id' => $position, 'clock_methods' => ['till', 'web', 'device'],
            'biometric_ref' => "BIO-{$upper}", 'date_of_birth' => '1990-04-12',
        ], $owner), 201)->json('data.id');
        // Linked to the Owner for self-service. Nobody links themselves through the API
        // (hr.employee.link, link_self), so the fixture links in the database.
        app(TenantContext::class)->run($tenantId, fn () => Employee::query()->whereKey($employee)->update(['user_id' => $ownerId]));
        $peer = self::ok($test->postJson('/api/v1/hr/employees', [
            'company_id' => $company, 'first_name' => 'Peer', 'last_name' => $upper, 'hire_date' => '2026-01-05', 'branch_id' => $branch, 'location_id' => $location, 'reports_to_id' => $employee,
        ], $owner), 201)->json('data.id');
        // HR-03: a national ID in the KE pack's researched format (7 to 9 digits).
        self::ok($test->putJson("/api/v1/hr/employees/{$employee}/identifiers", ['identifiers' => [['type' => 'national_id', 'value' => ($upper === 'A' ? '12345671' : '12345672')]]], $owner));
        $payAccount = self::ok($test->postJson("/api/v1/hr/employees/{$employee}/pay-accounts", [
            'kind' => 'bank', 'provider' => "Bank {$upper}", 'account_name' => "Worker {$upper}", 'account_number' => "ACC{$upper}00110022", 'currency' => 'KES',
        ], $owner), 201)->json('data.id');
        $contract = self::ok($test->postJson("/api/v1/hr/employees/{$employee}/contracts", [
            'type' => 'permanent', 'start_date' => '2026-01-05', 'pay_basis' => 'monthly', 'pay_amount' => '45000', 'currency' => 'KES', 'position_id' => $position,
        ], $owner), 201)->json('data.id');
        self::ok($test->putJson("/api/v1/hr/employees/{$employee}/clock-pin", ['pin' => '4321'], $owner));

        $leaveType = self::ok($test->postJson('/api/v1/hr/leave-types', [
            'company_id' => $company, 'code' => "AL-{$upper}", 'name' => "Annual {$upper}", 'accrual' => 'none', 'count_basis' => 'working_days', 'allow_negative' => true,
        ], $owner), 201)->json('data.id');
        self::ok($test->postJson('/api/v1/hr/leave-adjustments', ['employee_id' => $peer, 'leave_type_id' => $leaveType, 'days' => '5', 'reason' => "Opening {$upper}"], $owner), 201);
        $leaveRequest = self::ok($test->postJson('/api/v1/hr/leave-requests', [
            'employee_id' => $peer, 'leave_type_id' => $leaveType, 'start_date' => '2026-12-14', 'end_date' => '2026-12-15', 'reason' => "Family {$upper}",
        ], $owner), 201)->json('data.id');

        $shift = self::ok($test->postJson('/api/v1/hr/shifts', ['company_id' => $company, 'code' => "DAY-{$upper}", 'name' => "Day {$upper}", 'start_time' => '08:00', 'end_time' => '16:00'], $owner), 201)->json('data.id');
        $rotation = self::ok($test->postJson('/api/v1/hr/rotations', ['company_id' => $company, 'name' => "Rota {$upper}", 'days' => [$shift, null]], $owner), 201)->json('data.id');
        $roster = self::ok($test->postJson('/api/v1/hr/rosters', [
            'company_id' => $company, 'branch_id' => $branch, 'name' => "Roster {$upper}", 'rotation_id' => $rotation, 'anchor_date' => '2026-12-07',
            'members' => [['employee_id' => $employee, 'offset_days' => 0], ['employee_id' => $peer, 'offset_days' => 1]],
        ], $owner), 201)->json('data.id');
        $rosterShift = self::ok($test->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $employee, 'date' => '2026-12-01', 'shift_id' => $shift], $owner), 201)->json('data.id');
        $peerShift = self::ok($test->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $peer, 'date' => '2026-12-02', 'shift_id' => $shift], $owner), 201)->json('data.id');
        $swap = self::ok($test->postJson('/api/v1/hr/shift-swaps', [
            'employee_id' => $employee, 'roster_shift_id' => $rosterShift, 'peer_employee_id' => $peer, 'peer_roster_shift_id' => $peerShift, 'reason' => "Exam {$upper}",
        ], $owner), 201)->json('data.id');

        $timeRule = self::ok($test->postJson('/api/v1/hr/time-rules', [
            'company_id' => $company, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'daily_overtime_after_minutes' => 480, 'night_start' => '22:00', 'night_end' => '06:00',
        ], $owner), 201)->json('data.id');
        $punch = self::ok($test->postJson('/api/v1/hr/punches', ['employee_id' => $employee, 'kind' => 'in', 'occurred_at' => '2026-10-05T08:00:00+03:00', 'note' => "Gate {$upper}"], $owner), 201)->json('data.id');
        $period = self::ok($test->postJson('/api/v1/hr/attendance-periods', ['company_id' => $company, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31'], $owner), 201)->json('data.id');
        $attendanceDevice = self::ok($test->postJson('/api/v1/hr/attendance-devices', ['location_id' => $location, 'name' => "Gate reader {$upper}"], $owner), 201)->json('data.id');

        $checklistItem = self::ok($test->getJson("/api/v1/hr/checklists?employee={$employee}", $owner))->json('data.0.items.0.id');
        $case = self::ok($test->postJson('/api/v1/hr/disciplinary-cases', [
            'employee_id' => $employee, 'category' => 'verbal_warning', 'incident_date' => '2026-10-01', 'description' => "Late {$upper}",
        ], $owner), 201)->json('data.id');
        $cycle = self::ok($test->postJson('/api/v1/hr/appraisal-cycles', ['company_id' => $company, 'name' => "Review {$upper}", 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'], $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/hr/appraisal-cycles/{$cycle}/open", [], $owner));
        $appraisal = self::ok($test->getJson("/api/v1/hr/appraisals?employee={$employee}", $owner))->json('data.0.id');
        $document = self::ok($test->post("/api/v1/hr/employees/{$employee}/documents", [
            'category' => 'other', 'title' => "Letter {$upper}", 'file' => UploadedFile::fake()->create("letter-{$upper}.pdf", 4, 'application/pdf'),
        ], $owner), 201)->json('data.id');

        return [
            'hr_employee' => $employee,
            'hr_employee_peer' => $peer,
            'hr_position' => $position,
            'hr_pay_account' => $payAccount,
            'hr_contract' => $contract,
            'hr_leave_type' => $leaveType,
            'hr_leave_request' => $leaveRequest,
            'hr_shift' => $shift,
            'hr_rotation' => $rotation,
            'hr_roster' => $roster,
            'hr_roster_shift' => $rosterShift,
            'hr_roster_shift_peer' => $peerShift,
            'hr_shift_swap' => $swap,
            'hr_time_rule' => $timeRule,
            'hr_punch' => $punch,
            'hr_attendance_period' => $period,
            'hr_attendance_device' => $attendanceDevice,
            'hr_checklist_template' => $template,
            'hr_checklist_item' => $checklistItem,
            'hr_disciplinary_case' => $case,
            'hr_appraisal_cycle' => $cycle,
            'hr_appraisal' => $appraisal,
            'hr_document' => $document,
        ];
    }

    /**
     * The Fixed assets fixture of one tenant, made through its API as the Owner.
     *
     * @param  array<string, string>  $dimensions
     * @return array<string, string>
     */
    private static function fixedAssets(TestCase $test, array $owner, string $tenantId, string $ownerId, string $company, string $location, array $dimensions, string $party, string $each, string $upper): array
    {
        [$item, $spare] = app(TenantContext::class)->run($tenantId, function () use ($upper, $each) {
            app(ModuleRegistry::class)->activate('fixed_assets');

            return [
                Item::create(['code' => "FA-{$upper}", 'name' => "Laptop {$upper}", 'type' => 'non_stock', 'base_uom_id' => $each])->id,
                Item::create(['code' => "FA-SPARE-{$upper}", 'name' => "Printer {$upper}", 'type' => 'non_stock', 'base_uom_id' => $each])->id,
            ];
        });

        self::ok($test->putJson("/api/v1/fixed-assets/companies/{$company}/settings", ['convention' => 'days'], $owner));
        $category = self::ok($test->postJson('/api/v1/fixed-assets/categories', [
            'company_id' => $company, 'code' => "VEH-{$upper}", 'name' => "Vehicles {$upper}", 'method' => 'straight_line', 'useful_life_months' => 60,
        ], $owner), 201)->json('data.id');
        $itemCategory = self::ok($test->postJson('/api/v1/fixed-assets/item-categories', ['company_id' => $company, 'item_id' => $item, 'category_id' => $category], $owner), 201)->json('data.id');
        $asset = function (string $name) use ($test, $owner, $location, $category, $ownerId, $dimensions, $party) {
            $draft = self::ok($test->postJson('/api/v1/fixed-assets/assets', [
                'location_id' => $location, 'name' => $name, 'category_id' => $category, 'custodian_user_id' => $ownerId,
                'cost_centre_id' => $dimensions['cost_centre'], 'department_id' => $dimensions['department'], 'supplier_id' => $party,
                'acquired_on' => '2026-01-01', 'cost_minor' => 12000000, 'serial_number' => "SN-{$name}",
            ], $owner), 201)->json('data.id');
            self::ok($test->postJson("/api/v1/fixed-assets/assets/{$draft}/activate", [], $owner));

            return $draft;
        };
        $van = $asset("Van {$upper}");
        $truck = $asset("Truck {$upper}");
        $run = self::ok($test->postJson('/api/v1/fixed-assets/runs', ['company_id' => $company, 'period' => '2026-01'], $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/fixed-assets/assets/{$van}/revaluations", ['kind' => 'impairment', 'revalued_on' => '2026-02-15', 'value_minor' => 10000000, 'reason' => "Dent {$upper}"], $owner), 201);
        self::ok($test->postJson("/api/v1/fixed-assets/assets/{$van}/readings", ['read_on' => '2026-02-16', 'reading' => '12000'], $owner), 201);
        $document = self::ok($test->post("/api/v1/fixed-assets/assets/{$van}/documents", ['file' => UploadedFile::fake()->create("logbook-{$upper}.pdf", 4, 'application/pdf')], $owner), 201)->json('data.id');
        $schedule = self::ok($test->postJson("/api/v1/fixed-assets/assets/{$van}/schedules", ['name' => "Service {$upper}", 'basis' => 'time', 'interval_days' => 90], $owner), 201)->json('data.id');
        $workOrder = self::ok($test->postJson("/api/v1/fixed-assets/assets/{$van}/work-orders", ['title' => "Service {$upper}", 'schedule_id' => $schedule, 'vendor_id' => $party], $owner), 201)->json('data.id');
        $transfer = self::ok($test->postJson("/api/v1/fixed-assets/assets/{$van}/transfers", ['to_cost_centre_id' => null, 'transfer_date' => '2026-02-20', 'reason' => "Pool {$upper}"], $owner), 201)->json('data.id');
        $disposal = self::ok($test->postJson("/api/v1/fixed-assets/assets/{$truck}/disposals", ['kind' => 'scrap', 'disposal_date' => '2026-02-20', 'reason' => "Crash {$upper}"], $owner), 201)->json('data.id');

        return [
            'fa_category' => $category,
            'fa_item_category' => $itemCategory,
            'fa_item_spare' => $spare,
            'fa_asset' => $van,
            'fa_asset_truck' => $truck,
            'fa_run' => $run,
            'fa_document' => $document,
            'fa_schedule' => $schedule,
            'fa_work_order' => $workOrder,
            'fa_transfer' => $transfer,
            'fa_disposal' => $disposal,
        ];
    }

    /**
     * The CRM fixture of one tenant (docs/modules/crm.md): the module
     * switched on; the company's settings (its default pipeline, stages and
     * lists seeded), a team with the owner and the manager, an assignment
     * rule, a public lead form and a lead it captured, an open lead, a
     * converted lead with its opportunity, an open opportunity, a draft
     * quote with a line, an activity with a reminder, a logged email and a
     * target (so every crm_* table has rows).
     *
     * @return array<string, string> crm_pipeline, crm_stage, crm_source, crm_won_reason, crm_lost_reason, crm_team, crm_assignment_rule, crm_lead_form, crm_lead, crm_opportunity, crm_quote, crm_activity, crm_target
     */
    private static function crm(TestCase $test, array $owner, string $tenantId, string $ownerId, string $managerId, string $company, string $branch, string $customer, string $item, string $box, string $upper): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('crm'));
        $owner = [...$owner, 'Accept' => 'application/json'];

        self::ok($test->getJson("/api/v1/crm/companies/{$company}/settings", $owner));
        $pipeline = self::ok($test->getJson("/api/v1/crm/pipelines?company={$company}", $owner))->json('data.0');
        $options = collect(self::ok($test->getJson("/api/v1/crm/options?company={$company}", $owner))->json('data'));
        $team = self::ok($test->postJson('/api/v1/crm/teams', ['company_id' => $company, 'branch_id' => $branch, 'name' => "Team {$upper}", 'leader_id' => $ownerId,
            'members' => [['user_id' => $ownerId], ['user_id' => $managerId]]], $owner), 201)->json('data.id');
        self::ok($test->putJson("/api/v1/crm/companies/{$company}/settings", ['default_team_id' => $team, 'quote_validity_days' => 20], $owner));
        $rule = self::ok($test->postJson('/api/v1/crm/assignment-rules', ['company_id' => $company, 'name' => "Rule {$upper}", 'channels' => ['form'],
            'assignee_type' => 'team', 'team_id' => $team], $owner), 201)->json('data.id');
        $form = self::ok($test->postJson('/api/v1/crm/lead-forms', ['company_id' => $company, 'branch_id' => $branch, 'name' => "Form {$upper}",
            'fields' => ['shown' => ['email'], 'required' => ['email']]], $owner), 201)->json('data');
        self::ok($test->postJson((string) parse_url($form['url'], PHP_URL_PATH), ['name' => "Web lead {$upper}", 'email' => "web-{$upper}@example.com"]), 202);

        $lead = self::ok($test->postJson('/api/v1/crm/leads', ['branch_id' => $branch, 'name' => "Lead {$upper}", 'organisation' => "Prospect {$upper}", 'email' => "buyer-{$upper}@example.com",
            'source_id' => $options->firstWhere('kind', 'source')['id'], 'estimated_value_minor' => '500000', 'channel' => 'manual'], $owner), 201)->json('data.id');
        $converting = self::ok($test->postJson('/api/v1/crm/leads', ['branch_id' => $branch, 'name' => "Converted {$upper}", 'channel' => 'manual'], $owner), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/crm/leads/{$converting}/convert", ['party_id' => $customer, 'opportunity' => ['name' => "Won deal {$upper}", 'value_minor' => '300000']], $owner));
        $opportunity = self::ok($test->postJson('/api/v1/crm/opportunities', ['branch_id' => $branch, 'party_id' => $customer, 'name' => "Deal {$upper}",
            'value_minor' => '900000', 'currency' => 'KES', 'expected_close_on' => '2026-12-31'], $owner), 201)->json('data.id');
        $quote = self::ok($test->postJson('/api/v1/crm/quotes', ['branch_id' => $branch, 'party_id' => $customer, 'opportunity_id' => $opportunity,
            'terms' => "Terms {$upper}", 'lines' => [['item_id' => $item, 'uom_id' => $box, 'qty' => '2']]], $owner), 201)->json('data.id');
        $activity = self::ok($test->postJson('/api/v1/crm/activities', ['kind' => 'call', 'subject' => "Call {$upper}", 'starts_at' => '2026-12-01T08:00:00Z',
            'remind_at' => '2026-12-01T07:30:00Z', 'opportunity_id' => $opportunity], $owner), 201)->json('data.id');
        self::ok($test->postJson('/api/v1/crm/emails', ['to' => "buyer-{$upper}@example.com", 'subject' => "Hello {$upper}", 'body' => 'Following up.', 'quote_id' => $quote, 'lead_id' => $lead], $owner), 201);
        $target = self::ok($test->postJson('/api/v1/crm/targets', ['company_id' => $company, 'subject_type' => 'user', 'subject_id' => $ownerId, 'metric' => 'won_value',
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'amount_minor' => '10000000'], $owner), 201)->json('data.id');

        return [
            'crm_pipeline' => $pipeline['id'],
            'crm_stage' => $pipeline['stages'][1]['id'],
            'crm_source' => $options->firstWhere('kind', 'source')['id'],
            'crm_won_reason' => $options->firstWhere('kind', 'won_reason')['id'],
            'crm_lost_reason' => $options->firstWhere('kind', 'lost_reason')['id'],
            'crm_team' => $team,
            'crm_assignment_rule' => $rule,
            'crm_lead_form' => $form['id'],
            'crm_lead' => $lead,
            'crm_opportunity' => $opportunity,
            'crm_quote' => $quote,
            'crm_activity' => $activity,
            'crm_target' => $target,
        ];
    }

    /**
     * The E-commerce fixture of one tenant (docs/modules/ecommerce.md): the
     * module switched on; the item priced per unit; a live store on the
     * tenant's subdomain with a product, a zone, cash on delivery and M-Pesa;
     * a WhatsApp chat (a message in, a reply out); a customer signed in on the
     * storefront; the Owner as rider; an order delivered and cashed up, one
     * delivered and not yet cashed up (the cash-up route's control), and one
     * accepted and assigned (the routes' order and delivery).
     *
     * @return array{ids: array<string, string>, contacts: list<string>}
     */
    private static function ecommerce(TestCase $test, array $owner, string $tenantId, string $ownerId, string $company, string $branch, string $location, string $item, string $each,
        string $priceList, string $taxCode, string $paymentMethod, string $key, string $upper): array
    {
        $owner = [...$owner, 'Accept' => 'application/json'];
        [$cash, $slug] = app(TenantContext::class)->run($tenantId, function () use ($company) {
            app(ModuleRegistry::class)->activate('ecommerce');

            return [
                PaymentMethod::query()->where('company_id', $company)->where('type', 'cash')->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', 'KES'))->orderBy('position')->value('id'),
                Tenant::query()->value('slug'),
            ];
        });
        $baseDomain = config('branding.base_domain');
        config(['branding.base_domain' => 'shop.example.test']);
        $host = ['X-Shop-Host' => "{$slug}.shop.example.test", 'Accept' => 'application/json', 'Accept-Language' => 'en'];

        self::ok($test->postJson("/api/v1/price-lists/{$priceList}/prices", ['item_id' => $item, 'uom_id' => $each, 'amount_minor' => '11250', 'currency' => 'KES'], $owner), 201);
        $store = self::ok($test->postJson('/api/v1/ecommerce/stores', [
            'company_id' => $company, 'branch_id' => $branch, 'location_id' => $location, 'handle' => "duka-{$key}", 'name' => "Shop {$upper}", 'currency' => 'KES',
            'price_list_id' => $priceList, 'pickup_enabled' => true, 'pickup_location_ids' => [$location], 'delivery_tax_code_id' => $taxCode,
            'payment_methods' => [['kind' => 'cod', 'payment_method_id' => $cash], ['kind' => 'mpesa', 'payment_method_id' => $paymentMethod]],
        ], $owner), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/ecommerce/stores/{$store}", ['status' => 'live', 'contact_phone' => '+2547000004'.($key === 'a' ? '11' : '12')], $owner));
        $product = self::ok($test->postJson("/api/v1/ecommerce/stores/{$store}/products", ['item_ids' => [$item]], $owner), 201)->json('data.0.id');
        self::ok($test->patchJson("/api/v1/ecommerce/products/{$product}", ['published' => true, 'description' => "Sold online {$upper}"], $owner));
        $zone = self::ok($test->postJson("/api/v1/ecommerce/stores/{$store}/zones", ['name' => "Zone {$upper}", 'fee_minor' => '20000', 'areas' => ["Area {$upper}"]], $owner), 201)->json('data.id');

        // ECOM-24, ECOM-25: a chat by WhatsApp (the fake provider) and a reply.
        $webhook = self::ok($test->postJson("/api/v1/ecommerce/stores/{$store}/whatsapp-token", [], $owner), 201)->json('data.token');
        $chatPhone = $key === 'a' ? '254711000401' : '254711000402';
        $body = (string) json_encode(['messages' => [['id' => "wamid.{$key}.1", 'from' => $chatPhone, 'name' => "Chat {$upper}", 'text' => "Hello {$upper}"]]]);
        self::ok($test->call('POST', "/api/v1/shop/whatsapp/{$webhook}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => FakeWhatsAppProvider::sign($body)], $body));
        $conversation = app(TenantContext::class)->run($tenantId, fn () => Conversation::query()->value('id'));
        self::ok($test->postJson("/api/v1/ecommerce/conversations/{$conversation}/reply", ['text' => "Welcome {$upper}"], $owner), 201);

        // ECOM-08: a customer signs in on the storefront with a code.
        $shopperPhone = $key === 'a' ? '0712000501' : '0712000502';
        $challenge = self::ok($test->postJson("/api/v1/shop/duka-{$key}/auth/code", ['contact' => $shopperPhone], $host), 201)->json('data.challenge_id');
        $session = self::ok($test->postJson("/api/v1/shop/duka-{$key}/auth/verify", ['challenge_id' => $challenge, 'code' => self::last(CustomerCodeNotification::class)->code], $host))->json('data.token');

        // The Owner rides (ECOM-26); three cash on delivery orders.
        $rider = self::ok($test->postJson('/api/v1/ecommerce/riders', ['company_id' => $company, 'user_id' => $ownerId, 'name' => "Rider {$upper}"], $owner), 201)->json('data.id');
        $cart = ['lines' => [['product_id' => $product, 'qty' => '1']], 'fulfilment' => 'delivery', 'zone_id' => $zone];
        $total = self::ok($test->postJson("/api/v1/shop/duka-{$key}/quote", $cart, $host))->json('data.totals.total_minor');
        $order = function (int $n) use ($test, $host, $cart, $total, $key, $upper, $shopperPhone, $session, $owner, $rider, $tenantId) {
            $placed = self::ok($test->postJson("/api/v1/shop/duka-{$key}/orders", [...$cart, 'payment' => 'cod', 'expected_total_minor' => $total,
                'contact' => ['name' => "Shopper {$upper}", 'phone' => $shopperPhone, 'email' => "shopper-{$key}@example.com"], 'address' => ['line' => "Street {$upper} {$n}"]],
                [...$host, 'Authorization' => "Bearer {$session}", 'Idempotency-Key' => "fixture-order-{$key}-{$n}-0000"]), 201)->json('data.number');
            $id = app(TenantContext::class)->run($tenantId, fn () => Order::query()->where('number', $placed)->value('id'));
            self::ok($test->postJson("/api/v1/ecommerce/orders/{$id}/accept", [], $owner));
            $delivery = app(TenantContext::class)->run($tenantId, fn () => Delivery::query()->where('order_id', $id)->value('id'));
            self::ok($test->postJson("/api/v1/ecommerce/deliveries/{$delivery}/assign", ['rider_id' => $rider], $owner));

            return [$id, $delivery];
        };
        $deliver = function (string $id, string $delivery) use ($test, $owner) {
            self::ok($test->postJson('/api/v1/ecommerce/rider/updates', ['updates' => [['id' => (string) Str::uuid7(), 'delivery_id' => $delivery, 'kind' => 'status', 'status' => 'picked_up',
                'occurred_at' => now()->toIso8601String()]]], $owner));
            self::ok($test->postJson("/api/v1/ecommerce/orders/{$id}/deliver", [], $owner));
        };
        [$first, $firstDelivery] = $order(1);
        $deliver($first, $firstDelivery);
        self::ok($test->postJson('/api/v1/ecommerce/cashups', ['rider_id' => $rider, 'currency' => 'KES', 'counted_minor' => $total], $owner), 201);
        [$second, $secondDelivery] = $order(2);
        $deliver($second, $secondDelivery);
        // ECOM-42: part of the second order comes back (a return with its line), refunded by hand.
        $secondLine = app(TenantContext::class)->run($tenantId, fn () => OrderLine::query()->where('order_id', $second)->value('id'));
        $return = self::ok($test->postJson("/api/v1/ecommerce/orders/{$second}/returns", ['reason' => "Damaged {$upper}", 'lines' => [['line_id' => $secondLine, 'qty' => '1']]], $owner), 201)
            ->json('data.returns.0.id');
        [$open, $openDelivery] = $order(3);
        $openLine = app(TenantContext::class)->run($tenantId, fn () => OrderLine::query()->where('order_id', $open)->value('id'));
        // ECOM-40: the open delivery's rider reports where they are (a point of its trail).
        self::ok($test->postJson('/api/v1/ecommerce/rider/updates', ['updates' => [['id' => (string) Str::uuid7(), 'delivery_id' => $openDelivery, 'kind' => 'location',
            'latitude' => '-1.2633', 'longitude' => '36.8040', 'occurred_at' => now()->toIso8601String()]]], $owner));
        config(['branding.base_domain' => $baseDomain]);

        return [
            'ids' => [
                'ecom_store' => $store,
                'ecom_product' => $product,
                'ecom_zone' => $zone,
                'ecom_order' => $open,
                'ecom_delivery' => $openDelivery,
                'ecom_rider' => $rider,
                'ecom_conversation' => $conversation,
                'ecom_return' => $return,
                'ecom_order_line' => $openLine,
            ],
            'contacts' => [$webhook, $session, "+{$chatPhone}", '+254'.substr($shopperPhone, 1), "shopper-{$key}@example.com"],
        ];
    }

    /**
     * The Payroll fixture of one tenant (docs/modules/payroll.md), made through the API
     * with the fictional test pack (FixturePacks; the shipped packs have no figures).
     *
     * @return array<string, string>
     */
    private static function payroll(TestCase $test, array $owner, array $manager, string $tenantId, string $managerId, string $company, array $hr, string $upper): array
    {
        app(TenantContext::class)->run($tenantId, function () use ($managerId, $company) {
            app(ModuleRegistry::class)->activate('payroll');
            // The manager prepares runs (maker); the Owner approves them (checker, APR-07).
            RoleAssignment::create(['user_id' => $managerId, 'role_id' => Role::query()->where('template_key', 'payroll_officer')->value('id'), 'scope_type' => 'company', 'scope_id' => $company]);
        });
        app(PayrollPack::class)->fake('KE', FixturePacks::ke());

        self::ok($test->putJson("/api/v1/payroll/settings/{$company}", ['overtime_multiplier' => '1.5', 'monthly_hours' => '200', 'monthly_days' => '25'], $owner));
        $group = self::ok($test->postJson('/api/v1/payroll/pay-groups', ['company_id' => $company, 'code' => "PG-{$upper}", 'name' => "Monthly {$upper}", 'frequency' => 'monthly', 'pay_day' => 28], $owner), 201)->json('data.id');
        $earning = self::ok($test->postJson('/api/v1/payroll/items', ['company_id' => $company, 'code' => "BON-{$upper}", 'name' => "Bonus {$upper}", 'kind' => 'earning', 'taxable' => true, 'contributory' => true], $owner), 201)->json('data.id');
        self::ok($test->postJson('/api/v1/payroll/items', ['company_id' => $company, 'code' => "UNI-{$upper}", 'name' => "Union {$upper}", 'kind' => 'deduction'], $owner), 201);
        self::ok($test->postJson('/api/v1/payroll/employees/sync', ['company_id' => $company], $owner));

        [$employee, $peer] = app(TenantContext::class)->run($tenantId, fn () => [
            PayrollEmployee::query()->where('employee_id', $hr['hr_employee'])->value('id'),
            PayrollEmployee::query()->where('employee_id', $hr['hr_employee_peer'])->value('id'),
        ]);
        self::ok($test->patchJson("/api/v1/payroll/employees/{$employee}", ['pay_group_id' => $group, 'payment_method' => 'bank'], $owner));
        self::ok($test->patchJson("/api/v1/payroll/employees/{$peer}", ['pay_group_id' => $group, 'payment_method' => 'cash'], $owner));
        $employeeItem = self::ok($test->postJson("/api/v1/payroll/employees/{$employee}/items", [
            'item_id' => $earning, 'amount_minor' => '100000', 'currency' => 'KES', 'recurrence' => 'once', 'period_date' => '2026-09-10', 'note' => "Bonus {$upper}",
        ], $owner), 201)->json('data.id');
        $loan = self::ok($test->postJson('/api/v1/payroll/loans', [
            'payroll_employee_id' => $employee, 'kind' => 'loan', 'reference' => "LN-{$upper}", 'principal_minor' => '1000000', 'instalment_minor' => '100000', 'currency' => 'KES', 'disbursed_on' => '2026-09-01',
        ], $owner), 201)->json('data.id');

        // HR's events (HR-33): an unpaid leave day and a closed attendance period with overtime.
        app(TenantContext::class)->run($tenantId, function () use ($tenantId, $company, $hr) {
            event(new LeaveApproved($tenantId, (string) Str::uuid7(), 'LV-FIXTURE', $hr['hr_employee'], $company, $hr['hr_leave_type'], 'UNPAID', false, '2026-09-14', '2026-09-14', false, false, '1.00', [['date' => '2026-09-14', 'fraction' => '1.00']]));
            event(new AttendancePeriodClosed($tenantId, (string) Str::uuid7(), $company, '2026-09-01', '2026-09-30', 'Africa/Nairobi', null, [[
                'employee_id' => $hr['hr_employee'], 'days_present' => 20, 'days_absent' => 0, 'days_leave' => 1, 'worked_minutes' => 9660, 'regular_minutes' => 9600, 'overtime_minutes' => 60,
                'night_minutes' => 0, 'holiday_minutes' => 0, 'rest_day_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0,
            ]]));
        });

        $run = self::ok($test->postJson('/api/v1/payroll/runs', ['pay_group_id' => $group, 'period_date' => '2026-09-15'], $manager), 201)->json('data.id');
        self::ok($test->postJson("/api/v1/payroll/runs/{$run}/calculate", [], $manager));
        self::ok($test->postJson("/api/v1/payroll/runs/{$run}/submit", [], $manager));
        $approval = app(TenantContext::class)->run($tenantId, fn () => ApprovalRequest::query()->where('document_id', $run)->where('status', 'pending')->value('id'));
        self::ok($test->postJson("/api/v1/approvals/{$approval}/approve", [], $owner));
        self::ok($test->postJson("/api/v1/payroll/runs/{$run}/post", [], $manager));
        $payslip = app(TenantContext::class)->run($tenantId, fn () => Payslip::query()->where('run_id', $run)->where('payroll_employee_id', $employee)->value('id'));
        $return = self::ok($test->postJson('/api/v1/payroll/returns', ['company_id' => $company, 'type' => 'paye', 'period' => '2026-09'], $owner), 201)->json('data.id');

        return [
            'payroll_pay_group' => $group,
            'payroll_item' => $earning,
            'payroll_employee' => $employee,
            'payroll_employee_item' => $employeeItem,
            'payroll_loan' => $loan,
            'payroll_run' => $run,
            'payroll_payslip' => $payslip,
            'payroll_return' => $return,
        ];
    }

    /** The shared listed app, committed on the owner's connection and removed when the test ends. */
    private static function marketplaceApp(TestCase $test): void
    {
        $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
        $now = now();

        $owner->transaction(function () use ($owner, $now) {
            $owner->table('oauth_clients')->insert([
                'id' => self::MARKETPLACE_CLIENT, 'name' => 'Isolation app', 'secret' => 'external',
                'redirect_uris' => json_encode([self::MARKETPLACE_REDIRECT]), 'grant_types' => json_encode(['authorization_code', 'refresh_token']),
                'revoked' => false, 'app_id' => self::MARKETPLACE_APP, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $owner->table('marketplace_apps')->insert([
                'id' => self::MARKETPLACE_APP, 'developer_id' => FirstParty::DEVELOPER, 'slug' => 'isolation-listed-app', 'status' => 'active',
                'client_id' => self::MARKETPLACE_CLIENT, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $owner->table('marketplace_app_secrets')->insert([
                'app_id' => self::MARKETPLACE_APP, 'client_secret_sha256' => hash('sha256', self::MARKETPLACE_SECRET),
                'webhook_key' => DB::raw("decode('".bin2hex(random_bytes(32))."', 'hex')"), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $owner->table('marketplace_app_versions')->insert([
                'id' => self::MARKETPLACE_VERSION, 'app_id' => self::MARKETPLACE_APP, 'version' => 1, 'status' => 'draft', 'name' => 'Isolation app',
                'description' => 'A listed app both fixture tenants install.', 'category' => 'other', 'redirect_uris' => json_encode([self::MARKETPLACE_REDIRECT]),
                'scopes' => json_encode(['core.item.read', 'core.party.read']), 'webhook_events' => '[]', 'pricing' => json_encode(['model' => 'free']),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $owner->table('marketplace_app_versions')->where('id', self::MARKETPLACE_VERSION)->update(['status' => 'submitted']);
            $owner->table('marketplace_app_versions')->where('id', self::MARKETPLACE_VERSION)->update(['status' => 'approved']);
            $owner->table('marketplace_apps')->where('id', self::MARKETPLACE_APP)->update(['listed_version_id' => self::MARKETPLACE_VERSION]);
        });

        // After the test's transaction has rolled back (no install references the app any more).
        (fn () => $this->beforeApplicationDestroyed(function () {
            $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
            $owner->transaction(function () use ($owner) {
                $owner->statement('alter table marketplace_app_versions disable trigger user');
                $owner->statement('alter table marketplace_apps disable trigger user');
                $owner->table('marketplace_apps')->where('id', TwoTenants::MARKETPLACE_APP)->update(['listed_version_id' => null]);
                $owner->table('marketplace_app_versions')->where('app_id', TwoTenants::MARKETPLACE_APP)->delete();
                $owner->table('marketplace_app_secrets')->where('app_id', TwoTenants::MARKETPLACE_APP)->delete();
                $owner->table('marketplace_apps')->where('id', TwoTenants::MARKETPLACE_APP)->delete();
                $owner->table('oauth_clients')->where('id', TwoTenants::MARKETPLACE_CLIENT)->delete();
                $owner->statement('alter table marketplace_app_versions enable trigger user');
                $owner->statement('alter table marketplace_apps enable trigger user');
            });
        }))->call($test);
    }

    private static function ok(TestResponse $response, int $status = 200): TestResponse
    {
        // A host suspended mid-setup idles the fixture's sessions out (AUTH-09): say so.
        $note = $response->status() === $status ? '' : (string) self::$clock?->note();
        Assert::assertSame($status, $response->status(), 'TwoTenants setup failed: '.$response->getContent().$note);

        return $response;
    }

    /** The last notification of $class sent (Notification::fake()). */
    private static function last(string $class): object
    {
        $sent = [];

        foreach (Notification::sentNotifications() as $byId) {
            foreach ($byId as $byClass) {
                foreach ($byClass[$class] ?? [] as $entry) {
                    $sent[] = $entry['notification'];
                }
            }
        }

        Assert::assertNotEmpty($sent, "No {$class} was sent.");

        return end($sent);
    }
}
