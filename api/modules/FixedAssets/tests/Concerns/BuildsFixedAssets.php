<?php

namespace Modules\FixedAssets\Tests\Concerns;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Ledger\PeriodClose;
use Modules\Accounting\Ledger\Periods;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\FixedAssets\Models\Category;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with Fixed assets active (BuildsOrganisation's Acme, KE, base
 * KES, USD active at 130): categories Vehicles (straight line, 60 months,
 * 10 % residual), Computers (declining balance 40 %, 36 months), Plant
 * (units of production) and Land (none); a cost centre, a buyer party; a
 * Branch Manager at branch A (the default transfer and disposal approver)
 * and an Accountant at the company. Optionally Accounting with the KE
 * chart and its default rules. Time is fixed at 2026-10-15.
 */
trait BuildsFixedAssets
{
    use BuildsExchangeRates, BuildsOrganisation;

    protected Category $vehicles;

    protected Category $computers;

    protected Category $plant;

    protected Category $land;

    protected CostCentre $operations;

    protected Party $buyer;

    protected User $manager;

    protected User $accountant;

    protected function setUpFixedAssets(bool $accounting = false): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15T09:00:00Z'));
        $this->setUpOrganisation();

        $this->inTenant(function () use ($accounting) {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(ModuleRegistry::class)->activate('fixed_assets');

            if ($accounting) {
                app(ModuleRegistry::class)->activate('accounting');
                app(ModuleRegistry::class)->activate('accounting_pro');
                app(Charts::class)->fromTemplate($this->acme);
            }

            $this->vehicles = Category::query()->create(['company_id' => $this->acme->id, 'code' => 'VEH', 'name' => 'Vehicles', 'method' => 'straight_line', 'useful_life_months' => 60, 'residual_percent' => '10']);
            $this->computers = Category::query()->create(['company_id' => $this->acme->id, 'code' => 'IT', 'name' => 'Computers', 'method' => 'declining_balance', 'useful_life_months' => 36, 'declining_rate' => '40']);
            $this->plant = Category::query()->create(['company_id' => $this->acme->id, 'code' => 'PLANT', 'name' => 'Plant', 'method' => 'units_of_production']);
            $this->land = Category::query()->create(['company_id' => $this->acme->id, 'code' => 'LAND', 'name' => 'Land', 'method' => 'none']);
            $this->operations = CostCentre::create(['company_id' => $this->acme->id, 'code' => 'OPS', 'name' => 'Operations']);
            $this->buyer = Party::create(['kind' => 'organisation', 'name' => 'Baraka Motors', 'roles' => ['customer'], 'company_id' => $this->acme->id]);
        });

        $this->rate('USD', 'KES', '130', at: '2026-01-01T00:00:00Z');
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->accountant = $this->userWith('accountant', Scope::company($this->acme->id));
    }

    protected function api(string $method, string $uri, array $body = [], ?User $as = null): TestResponse
    {
        return $this->json($method, '/api/v1/fixed-assets/'.ltrim($uri, '/'), $body, $this->headersFor($as ?? $this->owner));
    }

    /** A draft asset at outlet A through the API (a Vehicles asset of KES 1 200 000.00 bought 2026-01-01 unless $extra says otherwise). */
    protected function draft(array $extra = [], ?User $as = null): array
    {
        return $this->api('POST', 'assets', [
            'location_id' => $this->locationA->id,
            'name' => 'Delivery van',
            'category_id' => $this->vehicles->id,
            'acquired_on' => '2026-01-01',
            'cost_minor' => 120000000,
            ...$extra,
        ], $as)->assertCreated()->json('data');
    }

    /** A draft activated (capitalised). */
    protected function active(array $extra = [], array $activate = []): array
    {
        $draft = $this->draft($extra);

        return $this->api('POST', "assets/{$draft['id']}/activate", $activate)->assertOk()->json('data');
    }

    /** Approve a document's waiting approval as $as (the branch manager by default). */
    protected function approve(string $documentId, ?User $as = null): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $documentId)->sole()->id);
        $this->json('POST', "/api/v1/approvals/{$approval}/approve", [], $this->headersFor($as ?? $this->manager))->assertOk();
    }

    protected function reject(string $documentId, ?User $as = null): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $documentId)->sole()->id);
        $this->json('POST', "/api/v1/approvals/{$approval}/reject", ['comment' => 'Not now'], $this->headersFor($as ?? $this->manager))->assertOk();
    }

    /** Accounting: close the company's periods up to the end of $month (Y-m). */
    protected function closeBooksThrough(string $month): void
    {
        $this->inTenant(function () use ($month) {
            $period = app(Periods::class)->forDate($this->acme, CarbonImmutable::parse($month.'-01'));
            app(PeriodClose::class)->close($period, $this->owner);
        });
    }

    /**
     * Accounting: every journal posted from $sourceType, as [code => [debit, credit]] in base minor units,
     * after checking each journal balances.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    protected function ledger(string $sourceType): array
    {
        return $this->inTenant(function () use ($sourceType) {
            $totals = [];

            foreach (Journal::query()->where('source_type', $sourceType)->where('status', 'posted')->get() as $journal) {
                $lines = JournalLine::query()->where('journal_id', $journal->id)->with('account')->get();
                $this->assertSame((int) $lines->sum('base_debit_minor'), (int) $lines->sum('base_credit_minor'), "journal {$journal->id} of {$sourceType} balances");

                foreach ($lines as $line) {
                    $code = $line->account->code;
                    $totals[$code] ??= [0, 0];
                    $totals[$code][0] += (int) $line->base_debit_minor;
                    $totals[$code][1] += (int) $line->base_credit_minor;
                }
            }

            ksort($totals);

            return $totals;
        });
    }
}
