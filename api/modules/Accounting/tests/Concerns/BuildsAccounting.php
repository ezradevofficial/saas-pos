<?php

namespace Modules\Accounting\Tests\Concerns;

use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankAccount;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with Accounting and Accounting Pro active: BuildsOrganisation's
 * Acme (KE, base KES, USD active) with the KE chart from its template, a
 * synthetic test VAT code at 12.5 % (never a real rate), a customer and a
 * supplier, a KES bank account on 1120 and an M-Pesa account on 1130,
 * and an Accountant at the company. Time is fixed at 2026-10-15.
 */
trait BuildsAccounting
{
    use BuildsExchangeRates, BuildsOrganisation;

    protected TaxCode $vat;

    protected Party $customer;

    protected Party $supplier;

    protected BankAccount $bank;

    protected BankAccount $mpesa;

    protected User $accountant;

    protected function setUpAccounting(bool $pro = true): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15T09:00:00Z'));
        $this->setUpOrganisation();

        $this->inTenant(function () use ($pro) {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(ModuleRegistry::class)->activate('accounting');

            if ($pro) {
                app(ModuleRegistry::class)->activate('accounting_pro');
            }

            app(Charts::class)->fromTemplate($this->acme);
            $this->vat = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_T', 'name' => 'VAT test', 'kind' => 'vat']);
            TaxRate::create(['tax_code_id' => $this->vat->id, 'rate' => '12.5', 'effective_from' => '2026-01-01']);
            $this->customer = Party::create(['kind' => 'organisation', 'name' => 'Baraka Traders', 'roles' => ['customer'], 'company_id' => $this->acme->id, 'payment_terms_days' => 30]);
            $this->supplier = Party::create(['kind' => 'organisation', 'name' => 'Upesi Supplies', 'roles' => ['supplier'], 'company_id' => $this->acme->id]);
            $this->bank = BankAccount::create(['company_id' => $this->acme->id, 'name' => 'Main bank', 'kind' => 'bank', 'account_id' => $this->account('1120')->id, 'currency' => 'KES']);
            $this->mpesa = BankAccount::create(['company_id' => $this->acme->id, 'name' => 'M-Pesa till', 'kind' => 'mobile_money', 'account_id' => $this->account('1130')->id, 'currency' => 'KES']);
        });

        $this->accountant = $this->userWith('accountant', Scope::company($this->acme->id));
    }

    protected function account(string $code): Account
    {
        return $this->inTenant(fn () => Account::query()->where('company_id', $this->acme->id)->where('code', $code)->firstOrFail());
    }

    /** A balanced manual journal body: $lines as [code, debit, credit]. */
    protected function journalBody(string $date, array $lines, array $extra = []): array
    {
        return [
            'date' => $date,
            'memo' => 'Test journal',
            'lines' => array_map(fn (array $line) => array_filter([
                'account_id' => $this->account($line[0])->id, 'debit' => $line[1], 'credit' => $line[2], ...($line[3] ?? []),
            ], fn ($v) => $v !== null), $lines),
            ...$extra,
        ];
    }

    protected function api(string $method, string $uri, array $body = [], ?User $as = null): TestResponse
    {
        return $this->json($method, '/api/v1/'.ltrim($uri, '/'), $body, $this->headersFor($as ?? $this->owner));
    }

    protected function companyUri(string $path): string
    {
        return "companies/{$this->acme->id}/accounting/{$path}";
    }

    /** An invoice (or other document) drafted and posted through the API; returns its JSON. */
    protected function postedDocument(string $kind, array $lines, array $extra = [], ?string $party = null): array
    {
        $party ??= str_starts_with($kind, 'customer') ? $this->customer->id : $this->supplier->id;
        $draft = $this->api('POST', $this->companyUri('documents'), [
            'kind' => $kind, 'party_id' => $party, 'issue_date' => '2026-10-10',
            'lines' => array_map(fn (array $line) => ['description' => $line['description'] ?? 'Goods', 'account_id' => $this->account($line['account'])->id,
                'qty' => $line['qty'] ?? '1', 'unit_price' => $line['price'], 'tax_code_id' => $line['tax'] ?? null], $lines),
            ...$extra,
        ])->assertCreated()->json('data');

        return $this->api('POST', "accounting/documents/{$draft['id']}/post")->assertOk()->json('data');
    }
}
