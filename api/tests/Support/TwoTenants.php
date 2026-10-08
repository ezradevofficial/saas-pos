<?php

namespace Tests\Support;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Automation\Events\RecordChanged;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Models\User;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\Support\Automation\FakeHostResolver;
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
 * rates from the country pack, a tax category and a price list, a
 * master data sharing setting, a shared customer and a per-company
 * supplier, item categories and an item with another unit, barcodes and
 * an image, configured payment methods, departments, cost centres and
 * projects, a published workflow with a document in it that created an
 * order, working hours, notification texts, settings and preferences, and
 * a notification sent to the owner and the manager, an automation rule
 * and its run, and the POS module with a till's ranges, shift, sales, a void,
 * a refund and a cash movement). Field rules, limit
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
        Http::fake(['https://hooks.example.com/*' => Http::response('received', 200)]);
        app()->instance(HostResolver::class, new FakeHostResolver(['hooks.example.com' => [['93.184.216.34']]]));
        app(ModuleRegistry::class)->register(self::MODULE);
        // WF-01: the test document types (a request that creates orders).
        TestDocuments::reset();
        // AUTO-01: the fixture raises RecordChanged for requests as their module would.
        $requests = new TestRequestType;
        $requests->raisesRecords = true;
        app(DocumentTypeRegistry::class)->register($requests);
        app(DocumentTypeRegistry::class)->register(TestOrderType::class);

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
        $itemImage = self::ok($test->post("/api/v1/items/{$item}/images", ['image' => UploadedFile::fake()->image('item.jpg', 8, 8)], [...$owner, 'Accept' => 'application/json']), 201)
            ->json('data.images.0.id');

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

        // TEN-05: a device, paired with its one-time code.
        $device = self::ok($test->postJson("/api/v1/locations/{$location}/devices", ['name' => "Till {$upper}"], $owner), 201)->json('data.id');
        $code = self::ok($test->postJson("/api/v1/devices/{$device}/pairing-code", [], $owner))->json('code');
        $deviceToken = self::ok($test->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => "Tablet {$upper}"]))->json('token');

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

        // POS (docs/modules/pos.md), NUM-01, NUM-02: the module switched on (no
        // API yet), then the till as it works: receipt and refund ranges (the
        // tenant's number formats seeded on first use), a shift with a float,
        // three M-Pesa sales by the owner (one voided, one partly refunded,
        // one left for the isolation suite to void) and a cash pay-in.
        $pos = self::pos($test, $tenantId, $ownerId, $deviceToken, $item, $uoms['EA'], $priceList, $paymentMethod, $taxCode);

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
                ...$dimensions,
                ...$pos,
                'challenge' => $challenge,
            ],
            tokens: ['owner' => $ownerToken, 'manager' => $accepted->json('token'), 'device' => $deviceToken],
            contacts: array_values(array_filter([$login['email'] ?? null, $login['phone'] ?? null, $managerEmail, $inviteePhone, $partyPhone, $webhookSecret, "hook-token-{$key}"])),
        );
    }

    /**
     * The POS fixture of one tenant, made through the device API.
     *
     * @return array<string, string> pos_shift, pos_sale (partly refunded), pos_sale_line, pos_sale_spare (completed, untouched), and the range patterns
     */
    private static function pos(TestCase $test, string $tenantId, string $ownerId, string $deviceToken, string $item, string $uom, string $priceList, string $paymentMethod, string $taxCode): array
    {
        app(TenantContext::class)->run($tenantId, fn () => app(ModuleRegistry::class)->activate('pos'));
        $device = ['Authorization' => 'Bearer '.$deviceToken, 'Accept' => 'application/json'];
        $id = fn () => (string) Str::uuid7();

        $receipts = self::ok($test->postJson('/api/v1/pos/number-ranges', ['document_type' => 'pos.receipt'], $device))->json('data.0.pattern');
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

        self::ok($test->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $id(), 'sale_id' => $sales[1]['id'], 'voided_by_id' => $ownerId, 'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong item',
        ]]], $device))->assertJsonPath('results.0.status', 'stored');
        self::ok($test->postJson('/api/v1/pos/refunds', ['refunds' => [self::posRefund($id, $sales[0], $shift, $ownerId, 1, str_replace('{000001}', '000001', $refunds), $paymentMethod)]], $device))
            ->assertJsonPath('results.0.status', 'stored');
        self::ok($test->postJson('/api/v1/pos/cash-movements', ['movements' => [[
            'id' => $id(), 'shift_id' => $shift, 'user_id' => $ownerId, 'kind' => 'pay_in', 'currency' => 'KES',
            'amount_minor' => '100000', 'reason' => 'Float top-up', 'occurred_at' => now()->toIso8601String(),
        ]]], $device))->assertJsonPath('results.0.status', 'stored');

        return [
            'pos_shift' => $shift,
            'pos_sale' => $sales[0]['id'],
            'pos_sale_line' => $sales[0]['lines'][0]['id'],
            'pos_sale_spare' => $sales[2]['id'],
            'pos_receipt_pattern' => $receipts,
            'pos_refund_pattern' => $refunds,
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
