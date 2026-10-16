<?php

namespace Modules\Accounting\Tests;

use App\Core\Rbac\ModuleRegistry;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ACC-24 (stage 7D closing review item 13): a module switched on after the chart was made gets the
// default posting rules it has none for; rules the tenant has, or archived, stay as they are.
class ModuleRulesTest extends TestCase
{
    use BuildsAccounting, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    /** @return array<string, list<bool>> event type => archived flags of its rules */
    private function rules(string $prefix): array
    {
        return $this->inTenant(fn () => PostingRule::query()->where('company_id', $this->acme->id)->where('event_type', 'like', $prefix.'%')
            ->orderBy('event_type')->get()->groupBy('event_type')->map(fn ($rules) => $rules->map(fn ($rule) => $rule->archived_at !== null)->all())->all());
    }

    public function test_activating_a_module_seeds_only_its_missing_rules(): void
    {
        // A chart made before CRM's and Fixed assets' rules existed: they are missing; one was archived on purpose.
        $this->inTenant(function () {
            PostingRule::query()->where('company_id', $this->acme->id)->whereIn('event_type', ['crm.quote_invoice', 'fixed_assets.capitalisation'])->delete();
            PostingRule::query()->where('company_id', $this->acme->id)->where('event_type', 'fixed_assets.depreciation')->sole()->archive();
        });
        $accounts = $this->inTenant(fn () => Account::query()->count());
        $this->assertSame([], $this->rules('crm.'));

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('crm'));

        $this->assertSame(['crm.quote_invoice' => [false]], $this->rules('crm.'));
        // The template's rule names the revenue account only (the invoice posts to the receivable control account).
        $lines = $this->inTenant(fn () => PostingRule::query()->where('event_type', 'crm.quote_invoice')->sole()->lines);
        $this->assertSame([['revenue', 'credit', $this->account('4100')->id]], array_map(fn (array $line) => [$line['field'], $line['side'], $line['account_id']], $lines));

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('fixed_assets'));

        $fixedAssets = $this->rules('fixed_assets.');
        $this->assertSame([false], $fixedAssets['fixed_assets.capitalisation']);
        $this->assertSame([true], $fixedAssets['fixed_assets.depreciation'], 'an archived rule is not brought back');
        $this->assertSame($accounts, $this->inTenant(fn () => Account::query()->count()), 'the chart had every account the rules name');

        // Again: nothing more.
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('crm'));
        $this->assertSame(['crm.quote_invoice' => [false]], $this->rules('crm.'));
    }

    public function test_nothing_is_seeded_while_accounting_is_off(): void
    {
        $this->inTenant(function () {
            PostingRule::query()->where('company_id', $this->acme->id)->where('event_type', 'crm.quote_invoice')->delete();
            app(ModuleRegistry::class)->deactivate('accounting');
            app(ModuleRegistry::class)->activate('crm');
        });

        $this->assertSame([], $this->rules('crm.'));
    }
}
