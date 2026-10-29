<?php

namespace Tests\Feature\Core\Search;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Search\RecordSearch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\RegistersTillModule;
use Tests\Support\TenantFixture;
use Tests\Support\TwoTenants;
use Tests\TestCase;

/**
 * LAY-01, TEN-01, RBAC-04, RBAC-05, RBAC-08: the record search of the quick
 * search, on the two-tenant fixture. Every searcher: the user's own records
 * are found and another tenant's never are; without the view permission
 * nothing of that type comes back; a branch-scoped user finds only their
 * branches' records; an inactive module is skipped; and fields hidden by
 * field rules are neither matched nor shown.
 */
class RecordSearchTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase, RegistersTillModule;

    /** type => the permission that lets a user find it. */
    private const PERMISSIONS = [
        'customer' => 'core.party.view',
        'supplier' => 'core.party.view',
        'item' => 'core.item.view',
        'billing_invoice' => 'core.subscription.view',
        'sale' => 'pos.sale.view',
        'sales_invoice' => 'accounting.invoice.view',
        'purchase_order' => 'procurement.po.view',
        'employee' => 'hr.employee.view',
        'reservation' => 'hotel.reservation.view',
        'salon_client' => 'salon.client.view',
    ];

    /** type => the module that owns it. */
    private const MODULES = [
        'customer' => 'core', 'supplier' => 'core', 'item' => 'core', 'billing_invoice' => 'core',
        'sale' => 'pos', 'sales_invoice' => 'accounting', 'purchase_order' => 'procurement',
        'employee' => 'hr', 'reservation' => 'hotel', 'salon_client' => 'salon',
    ];

    private TwoTenants $tenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerTillModule();
        $this->tenants = TwoTenants::build($this);
        app(TenantContext::class)->set(null);
        // The test signs many users in and searches far more often than a person; the limit is tested in RecordSearchHttpTest.
        RateLimiter::for('record-search', fn () => Limit::none());
        $this->withoutMiddleware(ThrottleRequests::class);
        // The machine running the suite may be slow; the 2 s budget is not what these tests are about.
        config(['search.budget_ms' => 60000]);
    }

    /**
     * One test, one TwoTenants build (about two minutes of set-up): the scenarios
     * run in turn on the same fixture, the module switch-off last.
     */
    public function test_the_record_search_follows_tenancy_permissions_scope_modules_and_field_rules(): void
    {
        $this->theRegistryListsASearcherPerDocumentedType();
        $this->findsOwnRecordsAndNeverAnotherTenants();
        $this->withoutTheViewPermissionNothingOfThatTypeComesBack();
        $this->branchScopedUsersFindOnlyTheirBranches();
        $this->hiddenFieldsAreNeitherMatchedNorShown();
        $this->inactiveModulesAreSkipped();
    }

    private function findsOwnRecordsAndNeverAnotherTenants(): void
    {
        foreach ([$this->tenants->a, $this->tenants->b] as $tenant) {
            $other = $tenant === $this->tenants->a ? $this->tenants->b : $this->tenants->a;
            $foreign = array_flip($other->ids);

            foreach ($this->terms($tenant) as $type => [$id, $query]) {
                $found = $this->search($tenant->bearer(), $query);

                $this->assertContains($id, $found[$type] ?? [], "A search for [{$query}] should find the {$type} {$id}");

                foreach ($found as $group => $ids) {
                    foreach ($ids as $hit) {
                        $this->assertArrayNotHasKey($hit, $foreign, "A search for [{$query}] found a {$group} of the other tenant");
                    }
                }
            }
        }

        // The record, not the tenant's name for it, is found: every hit opens an existing web page.
        $response = $this->getJson('/api/v1/search?q=Customer+A', $this->tenants->a->bearer())->assertOk();
        $hit = collect($response->json('data'))->firstWhere('type', 'customer')['results'][0];
        $this->assertSame(['type', 'id', 'title', 'subtitle', 'path'], array_keys($hit));
        $this->assertSame('/contacts/customers/'.$hit['id'], $hit['path']);
        $this->assertLessThanOrEqual(5, count(collect($response->json('data'))->firstWhere('type', 'customer')['results']));
    }

    private function withoutTheViewPermissionNothingOfThatTypeComesBack(): void
    {
        $a = $this->tenants->a;
        $permissions = array_values(array_unique(array_values(self::PERMISSIONS)));

        foreach ($permissions as $missing) {
            $user = $this->userWith($a, "all but {$missing}", array_values(array_diff($permissions, [$missing])), Scope::tenant());
            $affected = array_keys(array_filter(self::PERMISSIONS, fn (string $permission) => $permission === $missing));

            foreach ($this->terms($a) as $type => [$id, $query]) {
                $found = $this->search($this->headers($user), $query);

                in_array($type, $affected, true)
                    ? $this->assertArrayNotHasKey($type, $found, "Without {$missing}, a search for [{$query}] still found {$type}")
                    : $this->assertContains($id, $found[$type] ?? [], "With {$missing} missing, {$type} should still be found for [{$query}]");
            }
        }

        // The user holding none of them finds nothing at all.
        $nobody = $this->userWith($a, 'nothing', [], Scope::tenant());
        foreach ($this->terms($a) as [, $query]) {
            $this->assertSame([], $this->search($this->headers($nobody), $query));
        }
    }

    private function branchScopedUsersFindOnlyTheirBranches(): void
    {
        $a = $this->tenants->a;
        $permissions = array_values(array_unique(array_values(self::PERMISSIONS)));
        $terms = $this->terms($a);

        $elsewhere = app(TenantContext::class)->run($a->tenantId, function () use ($a) {
            $company = Company::query()->findOrFail($a->id('company'));
            $branch = $this->branch($company, 'Z9');
            $this->location($branch, 'Outlet Z9');

            return $branch;
        });

        $away = $this->userWith($a, 'at another branch', $permissions, Scope::branch($elsewhere->id));
        $here = $this->userWith($a, 'at the fixture branch', $permissions, Scope::branch($a->id('branch')));

        // Records of the fixture's branch and location: only the user at that branch finds them.
        foreach (['sale', 'purchase_order', 'employee', 'reservation'] as $type) {
            [$id, $query] = $terms[$type];
            $this->assertNotContains($id, $this->search($this->headers($away), $query)[$type] ?? [], "A user at another branch found the {$type}");
            $this->assertContains($id, $this->search($this->headers($here), $query)[$type] ?? [], "A user at the branch should find the {$type}");
        }

        // Company-level records (documents) and tenant-wide ones (the subscription) need a wider assignment.
        foreach (['sales_invoice', 'billing_invoice'] as $type) {
            [$id, $query] = $terms[$type];
            $this->assertArrayNotHasKey($type, $this->search($this->headers($here), $query));
        }

        // Shared master data is found from any branch.
        $this->assertContains($terms['item'][0], $this->search($this->headers($away), $terms['item'][1])['item'] ?? []);
        $this->assertContains($terms['customer'][0], $this->search($this->headers($away), $terms['customer'][1])['customer'] ?? []);

        // A company-scoped role reaches every branch's records and the company's documents.
        $company = $this->userWith($a, 'whole company', $permissions, Scope::company($a->id('company')));
        foreach (['sale', 'purchase_order', 'employee', 'reservation', 'sales_invoice'] as $type) {
            [$id, $query] = $terms[$type];
            $this->assertContains($id, $this->search($this->headers($company), $query)[$type] ?? [], "A company-scoped user should find the {$type}");
        }
    }

    private function inactiveModulesAreSkipped(): void
    {
        $a = $this->tenants->a;
        $terms = $this->terms($a);

        foreach (['pos', 'accounting', 'procurement', 'hr', 'hotel', 'salon'] as $module) {
            $types = array_keys(array_filter(self::MODULES, fn (string $owner) => $owner === $module));
            $this->assertNotEmpty($types);

            foreach ($types as $type) {
                $this->assertContains($terms[$type][0], $this->search($a->bearer(), $terms[$type][1])[$type] ?? [], "Control: {$type} is found while {$module} is active");
            }

            app(TenantContext::class)->run($a->tenantId, fn () => app(ModuleRegistry::class)->deactivate($module));

            foreach ($types as $type) {
                $this->assertArrayNotHasKey($type, $this->search($a->bearer(), $terms[$type][1]), "{$type} was found after {$module} was deactivated");
            }
        }

        // Core types remain.
        $this->assertContains($terms['item'][0], $this->search($a->bearer(), $terms['item'][1])['item'] ?? []);
    }

    private function hiddenFieldsAreNeitherMatchedNorShown(): void
    {
        $a = $this->tenants->a;
        $permissions = array_values(array_unique(array_values(self::PERMISSIONS)));
        $user = $this->userWith($a, 'restricted', $permissions, Scope::tenant());
        $owner = $a->bearer();

        // Control: the Owner finds the customer by the last digits of the phone, and sees the phone.
        $own = $this->searchResults($owner, '700000301');
        $this->assertSame($a->id('customer'), $own['customer'][0]['id']);
        $this->assertSame('+254700000301', $own['customer'][0]['subtitle']);

        $role = app(TenantContext::class)->run($a->tenantId, fn () => $this->roleOf($user));
        $hide = fn (string $resource, string $field) => app(TenantContext::class)->run($a->tenantId, fn () => FieldRule::create(['role_id' => $role, 'resource' => $resource, 'field' => $field, 'mode' => FieldRule::HIDDEN]));

        // Party phones: not matched (so nothing is found by digits), and not shown as the subtitle.
        $hide('party', 'phones');
        $this->assertArrayNotHasKey('customer', $this->searchResults($this->headers($user), '700000301'));
        $this->assertArrayNotHasKey('salon_client', $this->searchResults($this->headers($user), '700000301'));
        $byName = $this->searchResults($this->headers($user), 'Customer A');
        $this->assertSame($a->id('customer'), collect($byName['customer'])->firstWhere('id', $a->id('customer'))['id']);
        $this->assertNull(collect($byName['customer'])->firstWhere('id', $a->id('customer'))['subtitle']);

        // Item code: neither matched nor shown.
        $hide('item', 'code');
        $this->assertArrayNotHasKey('item', $this->searchResults($this->headers($user), 'ITEM-A'));
        // Found by a barcode (the fixture's other items share the "Item A" prefix), shown without the code.
        $barcode = (string) app(TenantContext::class)->run($a->tenantId, fn () => DB::connection(TenantContext::CONNECTION)->table('item_barcodes')->where('item_id', $a->id('item'))->value('barcode'));
        $item = collect($this->searchResults($this->headers($user), $barcode)['item'])->firstWhere('id', $a->id('item'));
        $this->assertNotNull($item);
        $this->assertNull($item['subtitle']);

        // Hotel guest name: the reservation is found by its number only, without the guest's name.
        [, $guestName] = [null, 'Hotel guest A'];
        $this->assertArrayHasKey('reservation', $this->searchResults($owner, $guestName), 'Control: the Owner finds the reservation by guest name');
        $hide('hotel_guest', 'name');
        $this->assertArrayNotHasKey('reservation', $this->searchResults($this->headers($user), $guestName));
        $number = $this->terms($a)['reservation'][1];
        $reservation = $this->searchResults($this->headers($user), $number)['reservation'][0];
        $this->assertNull($reservation['subtitle']);
    }

    private function theRegistryListsASearcherPerDocumentedType(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::PERMISSIONS), app(RecordSearch::class)->types());
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * type => [the id of the tenant's record, a query that finds it].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function terms(TenantFixture $t): array
    {
        $key = $t === $this->tenants->a ? 'A' : 'B';
        $value = fn (string $table, string $id, string $column) => (string) app(TenantContext::class)->run(
            $t->tenantId,
            fn () => DB::connection(TenantContext::CONNECTION)->table($table)->where('id', $id)->value($column),
        );

        $terms = [
            'customer' => [$t->id('customer'), "Customer {$key}"],
            'supplier' => [$t->id('party'), "Supplier {$key} Limited"],
            'item' => [$t->id('item'), "ITEM-{$key}"],
            'billing_invoice' => [$t->id('billing_invoice'), $value('billing_invoices', $t->id('billing_invoice'), 'number')],
            'sale' => [$t->id('pos_sale'), $value('pos_sales', $t->id('pos_sale'), 'receipt_number')],
            'sales_invoice' => [$t->id('acc_invoice'), $value('acc_documents', $t->id('acc_invoice'), 'number')],
            'purchase_order' => [$t->id('procurement_order_approved'), $value('procurement_orders', $t->id('procurement_order_approved'), 'number')],
            'employee' => [$t->id('hr_employee'), "Worker {$key}"],
            'reservation' => [$t->id('hotel_reservation'), $value('hotel_reservations', $t->id('hotel_reservation'), 'number')],
            'salon_client' => [$t->id('customer'), "Customer {$key}"],
        ];

        foreach ($terms as $type => [, $query]) {
            $this->assertGreaterThanOrEqual(2, strlen($query), "The fixture has no number for the {$type}");
        }

        return $terms;
    }

    /** @return array<string, list<string>> type => ids found */
    private function search(array $headers, string $query): array
    {
        return array_map(fn (array $results) => array_column($results, 'id'), $this->searchResults($headers, $query));
    }

    /** @return array<string, list<array<string, mixed>>> type => results */
    private function searchResults(array $headers, string $query): array
    {
        $response = $this->getJson('/api/v1/search?'.http_build_query(['q' => $query]), $headers);
        $this->assertSame(200, $response->getStatusCode(), "GET search?q={$query}: {$response->getContent()}");
        // A searcher that failed or ran out of time is skipped: say so, rather than report a type as not found.
        $this->assertFalse($response->json('meta.partial'), "GET search?q={$query} was partial (a searcher failed or the budget ran out; see the log)");

        return collect($response->json('data'))->mapWithKeys(fn (array $group) => [$group['type'] => $group['results']])->all();
    }

    /** @param list<string> $permissions */
    private function userWith(TenantFixture $t, string $name, array $permissions, Scope $scope): User
    {
        return app(TenantContext::class)->run($t->tenantId, function () use ($t, $name, $permissions, $scope) {
            $role = Role::create(['name' => $name, 'guard_name' => Permission::GUARD]);
            $role->givePermissionTo($permissions);
            $user = $this->colleague(User::query()->findOrFail($t->id('user')), ['email' => 'search-'.md5($name.$t->tenantId).'@example.com']);
            $this->assign($user, $role, $scope);

            return $user;
        });
    }

    private function roleOf(User $user): string
    {
        return (string) RoleAssignment::query()->where('user_id', $user->id)->value('role_id');
    }

    private function headers(User $user): array
    {
        return $this->bearer($this->tokenFor($user));
    }
}
