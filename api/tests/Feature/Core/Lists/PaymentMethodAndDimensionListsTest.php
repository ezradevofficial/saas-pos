<?php

namespace Tests\Feature\Core\Lists;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Lists and pickers plan, task 3: search, sort and export (EXP-01) on
// payment methods (MD-04; never settings or credentials) and on
// departments, cost centres and projects (MD-05), audited (AUD-01), never
// another tenant's rows (TEN-01).
class PaymentMethodAndDimensionListsTest extends TestCase
{
    use BuildsOrganisation, ReadsListExports, RefreshTenantDatabase;

    private const SECRET = 'zq-known-secret-7f3a9c';

    private const SHORTCODE = '174379';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => app(DefaultPaymentMethods::class)->seed($this->acme));
        });
    }

    private function url(string $path, string $query = ''): string
    {
        return "/api/v1/companies/{$this->acme->id}/{$path}{$query}";
    }

    /** M-Pesa configured with a shortcode and credentials, and switched on. */
    private function configureMpesa(): string
    {
        $mpesa = collect($this->getJson($this->url('payment-methods'), $this->headersFor())->json('data'))->firstWhere('provider', 'mpesa_ke')['id'];
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", [
            'active' => true,
            'settings' => ['shortcode' => self::SHORTCODE],
            'secrets' => ['consumer_key' => 'ck-'.self::SECRET, 'consumer_secret' => 'cs-'.self::SECRET, 'passkey' => 'pk-'.self::SECRET],
        ], $this->headersFor())->assertOk();

        return $mpesa;
    }

    public function test_payment_methods_search_sort_and_export(): void
    {
        $this->configureMpesa();
        $names = fn (string $query) => array_column($this->getJson($this->url('payment-methods', $query), $this->headersFor())->assertOk()->json('data'), 'name');

        // Till order by default.
        $this->assertSame(['Cash KES', 'Cash USD', 'M-Pesa', 'Airtel Money', 'Card'], $names(''));
        $this->assertSame(['Airtel Money', 'Card', 'Cash KES', 'Cash USD', 'M-Pesa'], $names('?sort=name'));
        $this->assertSame(['Card', 'Airtel Money', 'M-Pesa', 'Cash USD', 'Cash KES'], $names('?sort=-position'));
        $this->assertSame(['Cash KES', 'Cash USD'], $names('?search=cash'));
        $this->getJson($this->url('payment-methods', '?sort=settings'), $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson($this->url('payment-methods', '?format=csv&columns[]=secrets'), $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('columns.0');

        $rows = $this->csvRows($this->get($this->url('payment-methods', '?format=csv'), $this->headersFor())->assertOk());
        $this->assertSame(['Order', 'Name', 'Type', 'Provider', 'Currency', 'At the till', 'Status', 'Updated'], $rows[0]);
        $this->assertSame(['1', 'Cash KES', 'Cash', '', 'KES', 'On', 'Active'], array_slice($rows[1], 0, 7));
        $this->assertSame(['3', 'M-Pesa', 'Mobile money', 'M-Pesa (Safaricom Daraja)', '', 'On'], array_slice($rows[3], 0, 6));
        $this->assertSame(['Airtel Money', 'Setup needed'], [$rows[4][1], $rows[4][5]]);

        $fr = $this->csvRows($this->get($this->url('payment-methods', '?format=csv&search=airtel&columns[]=type&columns[]=till'), [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame([['Type', 'En caisse'], ['Mobile money', 'À configurer']], $fr);

        $this->inTenant(fn () => $this->assertSame(2, AuditEntry::where('action', 'core.payment_method.export')->count()));
    }

    public function test_no_setting_or_credential_reaches_a_payment_methods_export(): void
    {
        $this->configureMpesa();
        $leaks = ['ck-'.self::SECRET, 'cs-'.self::SECRET, 'pk-'.self::SECRET, self::SECRET, self::SHORTCODE, 'shortcode', 'consumer_key', 'passkey'];

        $csv = $this->get($this->url('payment-methods', '?format=csv'), $this->headersFor())->assertOk()->streamedContent();
        $xlsx = implode("\n", array_merge(...$this->xlsxRows($this->get($this->url('payment-methods', '?format=xlsx'), $this->headersFor())->assertOk())));
        $html = $this->capturePdfHtml(fn () => $this->get($this->url('payment-methods', '?format=pdf'), $this->headersFor())->assertOk()->streamedContent());

        foreach (['csv' => $csv, 'xlsx' => $xlsx, 'pdf' => $html] as $format => $text) {
            $this->assertStringContainsString('M-Pesa', $text, "control: the {$format} export lists M-Pesa");

            foreach ($leaks as $leak) {
                $this->assertStringNotContainsString($leak, $text, "the {$format} export contains {$leak}");
            }
        }

        // Nor the audit entry of the export.
        $this->inTenant(function () use ($leaks) {
            $after = json_encode(AuditEntry::where('action', 'core.payment_method.export')->get()->pluck('after')->all());

            foreach ($leaks as $leak) {
                $this->assertStringNotContainsString($leak, $after);
            }
        });
    }

    public function test_hidden_credentials_drop_the_till_column_and_a_hidden_name_is_not_searched(): void
    {
        $clerk = $this->inTenant(function () {
            $role = $this->role('Clerk', ['core.payment_method.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'payment_method', 'field' => 'secrets', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'payment_method', 'field' => 'name', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($this->acme->id));

            return $user;
        });
        $headers = $this->headersFor($clerk);

        $rows = $this->csvRows($this->get($this->url('payment-methods', '?format=csv'), $headers)->assertOk());
        $this->assertSame(['Order', 'Type', 'Provider', 'Currency', 'Status', 'Updated'], $rows[0]);
        $this->getJson($this->url('payment-methods', '?sort=name'), $headers)->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->assertSame([], $this->listIds($this->url('payment-methods', '?search=cash'), $headers));
    }

    public function test_dimensions_search_sort_and_export(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->inTenant(fn () => $manager->forceFill(['name' => 'Branch B head'])->save());

        foreach (['departments', 'cost-centres', 'projects'] as $path) {
            $root = $this->postJson($this->url($path), ['code' => 'OPS', 'name' => 'Operations', 'owner_user_id' => $this->owner->id], $this->headersFor())->assertCreated()->json('data.id');
            $child = $this->postJson($this->url($path), ['code' => 'ADM', 'name' => 'Administration', 'parent_id' => $root, 'owner_user_id' => $manager->id], $this->headersFor())->assertCreated()->json('data.id');

            $this->assertSame([$child, $root], $this->listIds($this->url($path), $this->headersFor()));
            $this->assertSame([$root, $child], $this->listIds($this->url($path, '?sort=-code'), $this->headersFor()));
            $this->assertSame([$child, $root], $this->listIds($this->url($path, '?sort=name'), $this->headersFor()));
            $this->assertSame([$root, $child], $this->listIds($this->url($path, '?sort=parent'), $this->headersFor()));
            $this->assertSame([$child], $this->listIds($this->url($path, '?search=admin'), $this->headersFor()));
            $this->getJson($this->url($path, '?sort=owner'), $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

            $response = $this->get($this->url($path, '?format=csv'), $this->headersFor())->assertOk();
            $this->assertMatchesRegularExpression("/attachment; filename={$path}-\\d{4}-\\d{2}-\\d{2}\\.csv/", $response->headers->get('Content-Disposition'));
            $rows = $this->csvRows($response);
            $this->assertSame(['Code', 'Name', 'Parent', 'Owner', 'Status', 'Created', 'Updated'], $rows[0]);
            $this->assertSame(['ADM', 'Administration', 'OPS · Operations', 'Branch B head', 'Active'], array_slice($rows[1], 0, 5));
            $this->assertSame(['OPS', 'Operations', '', 'Owner', 'Active'], array_slice($rows[2], 0, 5));
        }

        $this->inTenant(fn () => $this->assertSame([1, 1, 1], [
            AuditEntry::where('action', 'core.department.export')->count(),
            AuditEntry::where('action', 'core.cost_centre.export')->count(),
            AuditEntry::where('action', 'core.project.export')->count(),
        ]));
    }

    public function test_a_dimension_owner_out_of_the_readers_sight_is_not_named(): void
    {
        $owner = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->inTenant(fn () => $owner->forceFill(['name' => 'Branch B head'])->save());
        $this->postJson($this->url('departments'), ['code' => 'OPS', 'name' => 'Operations', 'owner_user_id' => $owner->id], $this->headersFor())->assertCreated();
        // An accountant of the company reads dimensions but not users.
        $accountant = $this->headersFor($this->userWith('accountant', Scope::company($this->acme->id)));

        $rows = $this->csvRows($this->get($this->url('departments', '?format=csv&columns[]=code&columns[]=owner'), $accountant)->assertOk());
        $this->assertSame([['Code', 'Owner'], ['OPS', 'Someone you can’t see']], $rows);
    }

    public function test_an_export_needs_the_lists_view_permission_and_never_shows_another_tenant(): void
    {
        // Both see the company, without the list's view permission.
        $hr = $this->headersFor($this->userWith('hr_officer', Scope::company($this->acme->id)));
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->refusedExport($this->url('payment-methods', "?format={$format}"), $hr)->assertForbidden();
            $this->refusedExport($this->url('departments', "?format={$format}"), $manager)->assertForbidden();
        }

        $other = $this->otherTenant();
        $this->refusedExport("/api/v1/companies/{$other['company']->id}/payment-methods?format=csv", $this->headersFor())->assertNotFound();
        $this->refusedExport("/api/v1/companies/{$other['company']->id}/projects?format=csv", $this->headersFor())->assertNotFound();

        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::where('action', 'like', 'core.%.export')->count()));
    }
}
