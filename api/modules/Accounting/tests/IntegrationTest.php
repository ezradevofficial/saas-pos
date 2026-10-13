<?php

namespace Modules\Accounting\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Fiscal\FiscalSources;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Modules\Accounting\Fiscal\AccountingFiscalSource;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\RecurringJournal;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** ACC-06, ACC-19, ACC-20, ACC-22: imports, fiscalisation, recurring journals, and every route's guards. */
class IntegrationTest extends TestCase
{
    use BuildsAccounting, BuildsImports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    public function test_the_chart_and_opening_balances_come_in_through_the_import_framework_and_undo(): void
    {
        $chart = $this->importFile('chart_of_accounts', $this->csvFile([
            ['Company', 'Code', 'Name', 'Type', 'Subtype', 'Parent code', 'Postable', 'Currency', 'Cash-flow class'],
            ['Acme', '6450', 'Internet', 'expense', 'expense', '6000', 'yes', '', ''],
            ['Acme', '1135', 'Airtel Money', 'asset', 'mobile_money', '1100', 'yes', '', 'cash'],
        ]));
        $this->assertSame('completed', $chart['status']);
        $this->assertSame('6000', $this->inTenant(fn () => $this->account('6450')->parent()->value('code')));

        $opening = $this->importFile('opening_balances', $this->csvFile([
            ['Company', 'Date', 'Account code', 'Debit', 'Credit', 'Currency', 'Rate', 'Reference'],
            ['Acme', '2026-01-01', '1120', '250000.00', '', '', '', 'Opening'],
            ['Acme', '2026-01-01', '3100', '', '250000.00', '', '', 'Opening'],
            ['Acme', '2026-01-01', '1200', '10.00', '', '', '', 'Refused: control account'],
        ]));
        $this->assertSame(2, $opening['created_rows']);
        $tb = collect($this->api('GET', $this->companyUri('reports/trial-balance').'?from=2026-01-01&to=2026-12-31')->json('data.rows'))->keyBy('account.code');
        $this->assertSame(25000000, $tb['1120']['closing']);
        $this->assertSame(0, $tb['1900']['closing']);

        $this->postJson("/api/v1/imports/{$opening['id']}/undo", [], $this->headersFor())->assertStatus(202);
        $tb = collect($this->api('GET', $this->companyUri('reports/trial-balance').'?from=2026-01-01&to=2026-12-31')->json('data.rows'))->keyBy('account.code');
        $this->assertSame(0, $tb['1120']['closing']);
    }

    public function test_posted_customer_documents_go_to_the_fiscal_queue_through_the_accounting_source(): void
    {
        $this->putJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", ['driver' => 'fake', 'tin' => 'P051111111A', 'enabled' => true], $this->headersFor())->assertSuccessful();
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00', 'tax' => $this->vat->id]]);
        $this->postedDocument('supplier_invoice', [['account' => '5200', 'price' => '40.00']]);

        $this->inTenant(function () use ($invoice) {
            $submission = FiscalSubmission::query()->where('source', AccountingFiscalSource::KEY)->sole();
            $this->assertSame(['sale', $invoice['id']], [$submission->document_type, $submission->document_id]);
            $document = app(FiscalSources::class)->get(AccountingFiscalSource::KEY)->document('sale', $invoice['id']);
            $this->assertSame(11250, $document->data['totals']['total_minor']);
        });
    }

    public function test_recurring_journals_are_made_on_their_dates_and_submitted_to_their_flow(): void
    {
        $this->userWith('accountant', Scope::company($this->acme->id));
        $this->api('POST', $this->companyUri('recurring-journals'), [
            'name' => 'Rent', 'frequency' => 'monthly', 'next_run_on' => '2026-10-01',
            'lines' => [['account_id' => $this->account('6200')->id, 'debit' => '30000.00'], ['account_id' => $this->account('1120')->id, 'credit' => '30000.00']],
        ], $this->accountant)->assertCreated();

        $this->artisan('accounting:recurring', ['--on' => '2026-11-15'])->assertSuccessful();

        $this->inTenant(function () {
            $journals = Journal::query()->where('kind', 'recurring')->orderBy('date')->get();
            $this->assertSame(['2026-10-01', '2026-11-01'], $journals->map(fn ($j) => $j->date->toDateString())->all());
            $this->assertSame(['pending', 'pending'], $journals->pluck('status')->all());
            $this->assertSame(2, ApprovalRequest::query()->where('document_type', 'accounting.journal')->count());
            $this->assertSame('2026-12-01', RecurringJournal::query()->sole()->next_run_on->toDateString());
        });
    }

    /**
     * ACC-22, RBAC-04, RBAC-08: every Accounting route refuses a user with
     * no Accounting permission at the company (404: never confirmed), a
     * read-only user's writes (403), and every route answers 403
     * `module_inactive` once the module is off.
     */
    public function test_every_route_refuses_users_without_the_permission_and_tenants_without_the_module(): void
    {
        $journal = $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-10-12', [['6200', '1.00', null], ['1120', null, '1.00']]))->json('data.id');
        $ids = $this->inTenant(fn () => [
            'company' => $this->acme->id,
            'acc_account' => $this->account('6200')->id,
            'acc_journal' => $journal,
            'acc_bank_account' => $this->bank->id,
        ]);
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $auditor = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_contains($route->uri(), 'accounting') && str_starts_with($route->uri(), 'api/v1/'))
            ->filter(fn (RoutingRoute $route) => array_diff($route->parameterNames(), array_keys($ids)) === [] && $route->parameterNames() !== []);
        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as $route) {
            $uri = '/'.preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $ids[$m[1]], $route->uri());
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];

            $this->assertSame(404, $this->json($method, $uri, [], $this->headersFor($cashier))->getStatusCode(), "{$method} {$uri} as a cashier");

            if ($method !== 'GET') {
                $this->assertSame(403, $this->json($method, $uri, [], $this->headersFor($auditor))->getStatusCode(), "{$method} {$uri} as the auditor");
            }
        }

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('accounting'));
        foreach ($routes as $route) {
            $uri = '/'.preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $ids[$m[1]], $route->uri());
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $this->json($method, $uri, [], $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
        }

    }
}
