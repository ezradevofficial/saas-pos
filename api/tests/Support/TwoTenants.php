<?php

namespace Tests\Support;

use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Notifications\InvitationNotification;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
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
 * an image). Field rules, limit rules and module flags have
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
        Storage::fake('media');
        app(ModuleRegistry::class)->register(self::MODULE);

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
        $parentCategory = self::ok($test->postJson('/api/v1/item-categories', ['name_en' => "Goods {$upper}"], $owner), 201)->json('data.id');
        $itemCategory = self::ok($test->postJson('/api/v1/item-categories', ['name_en' => "Goods {$upper} sub", 'parent_id' => $parentCategory], $owner), 201)->json('data.id');
        $item = self::ok($test->postJson('/api/v1/items', [
            'code' => "ITEM-{$upper}", 'name_en' => "Item {$upper}", 'type' => 'stock', 'base_uom_id' => $uoms['EA'],
            'category_id' => $itemCategory, 'tax_category_id' => $taxCategory,
            'uoms' => [['uom_id' => $uoms['BOX'], 'factor' => '12']],
            'barcodes' => [['barcode' => '6161000000001'], ['barcode' => '6161000000018', 'uom_id' => $uoms['BOX']]],
        ], $owner), 201)->json('data.id');
        self::ok($test->patchJson("/api/v1/items/{$item}", ['name_fr' => "Article {$upper}"], $owner));
        $itemImage = self::ok($test->post("/api/v1/items/{$item}/images", ['image' => UploadedFile::fake()->image('item.jpg', 8, 8)], [...$owner, 'Accept' => 'application/json']), 201)
            ->json('data.images.0.id');

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
        $accepted = self::ok($test->postJson('/api/v1/auth/invitations/'.self::last(InvitationNotification::class)->token.'/accept', [
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
                'challenge' => $challenge,
            ],
            tokens: ['owner' => $ownerToken, 'manager' => $accepted->json('token'), 'device' => $deviceToken],
            contacts: array_values(array_filter([$login['email'] ?? null, $login['phone'] ?? null, $managerEmail, $inviteePhone, $partyPhone])),
        );
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
