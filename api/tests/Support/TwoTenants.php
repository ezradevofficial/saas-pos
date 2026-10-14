<?php

namespace Tests\Support;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Automation\Events\RecordChanged;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Webhooks\HostResolver;
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
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Accounting\Models\QueuedSource;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Tenders\Bidders;
use PHPUnit\Framework\Assert;
use Tests\Support\Automation\FakeHostResolver;
use Tests\Support\Configuration\TestLayoutKind;
use Tests\Support\Fiscal\TestFiscalSource;
use Tests\Support\Limits\FixedAllowances;
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

    /** AUTH-06: the owner's POS PIN in both tenants (6 digits: owners approve overrides, AUTH-08). */
    public const PIN = '482619';

    private function __construct(
        public readonly TenantFixture $a,
        public readonly TenantFixture $b,
    ) {}

    public static function build(TestCase $test): self
    {
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
        // entered (a test figure), the pack applied again (adds nothing), a shared
        // tax category with a default code, and a default price list.
        $taxCode = collect(self::ok($test->getJson("/api/v1/companies/{$company}/tax-codes", $owner))->json('data'))->firstWhere('code', 'VAT_STD')['id'];
        self::ok($test->postJson("/api/v1/tax-codes/{$taxCode}/rates", ['rate' => '12.5', 'effective_from' => '2026-01-01'], $owner), 201);
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

        // TPL-01: a template preview the owner made (its PDF is kept for a few minutes).
        $templatePreview = basename(dirname((string) parse_url(self::ok($test->postJson('/api/v1/templates/preview', [
            'type' => 'sales.quote', 'payload' => DefaultTemplates::for('sales.quote'),
        ], $owner))->json('data.pdf_url'), PHP_URL_PATH)));
        // BR-02, BR-05: a brand asset (a logo) and a custom domain, still pending.
        $brandAsset = self::ok($test->post('/api/v1/branding/assets', ['kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 8, 8)], [...$owner, 'Accept' => 'application/json']), 201)->json('data.id');
        $tenantDomain = self::ok($test->postJson('/api/v1/branding/domains', ['host' => "erp-{$key}.example.org"], $owner), 201)->json('data.id');

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
        // IMP-01..IMP-03: one customer imported from a file (an import, its
        // row and the remembered mapping), with its result file.
        $csv = tempnam(sys_get_temp_dir(), 'two-tenants-import-');
        file_put_contents($csv, "Name,Tax ID\nImported {$upper},IMP{$upper}\n");
        $import = self::ok($test->post('/api/v1/imports', ['importer' => 'customers', 'file' => new UploadedFile($csv, "customers-{$key}.csv", 'text/csv', null, true)], [...$owner, 'Accept' => 'application/json']), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/imports/{$import}", ['mode' => 'create'], $owner));
        self::ok($test->postJson("/api/v1/imports/{$import}/run", [], $owner), 202);

        // LIM-01, LIM-02: a day's usage metered against a plan of no devices: the paired till is over,
        // so the tenant has meter rows and an open overage (the Owner is warned).
        $plans = app(PlanAllowances::class);
        $fixed = new FixedAllowances;
        $fixed->set($tenantId, new Allowances([Units::DEVICE => 0]));
        app()->instance(PlanAllowances::class, $fixed);
        app(TenantContext::class)->run($tenantId, fn () => app(UsageLimits::class)->meter(CarbonImmutable::now()));
        app()->instance(PlanAllowances::class, $plans);

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
                'brand_asset' => $brandAsset,
                'billing_invoice' => $billingInvoice,
                'billing_payment' => $billingPayment,
                'billing_payment_method' => $billingMethod,
                'import' => $import,
                ...$dimensions,
                ...$pos,
                ...$inventory,
                ...$procurement,
                ...$stores,
                ...$accounting,
                ...$loyalty,
                'challenge' => $challenge,
            ],
            tokens: ['owner' => $ownerToken, 'manager' => $accepted->json('token'), 'device' => $deviceToken],
            contacts: array_values(array_filter([$login['email'] ?? null, $login['phone'] ?? null, $managerEmail, $inviteePhone, $partyPhone, $webhookSecret, "hook-token-{$key}", $callbackToken])),
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

    private static function ok(TestResponse $response, int $status = 200): TestResponse
    {
        Assert::assertSame($status, $response->status(), 'TwoTenants setup failed: '.$response->getContent());

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
