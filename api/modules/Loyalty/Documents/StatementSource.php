<?php

namespace Modules\Loyalty\Documents;

use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DataSources;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\SampleSource;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use Modules\Loyalty\Credit\Statements;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\Statement;

/**
 * LOY-17, TPL-01, TPL-04: the live data source of `party.statement`: a
 * stored statement (loyalty_statements) in the document data shape. Lines
 * are the period's entries (date, description, debit, credit, running
 * balance); totals.total is the closing balance; the ageing buckets and
 * the opening balance are document fields. Sample data for the designer
 * stays core's (SampleSource).
 */
final class StatementSource implements RecordSource
{
    public const TYPE = 'party.statement';

    public const FIELDS = [
        'document.period' => 'text',
        'document.opening' => 'money',
        'document.closing' => 'money',
        'document.ageing_0_30' => 'money',
        'document.ageing_31_60' => 'money',
        'document.ageing_61_90' => 'money',
        'document.ageing_90_plus' => 'money',
    ];

    public function type(): string
    {
        return self::TYPE;
    }

    public function documentFields(): array
    {
        return self::FIELDS;
    }

    public function lineColumns(): array
    {
        return SampleSource::CATALOGUE[self::TYPE][1];
    }

    public function sample(?string $country): array
    {
        $sample = (new SampleSource(self::TYPE))->sample($country);
        $total = $sample['totals']['total'] ?? ['minor' => '0', 'currency' => array_key_first($sample['currencies'])];
        $zero = ['minor' => '0', 'currency' => $total['currency']];

        return [...$sample, 'document' => [
            ...$sample['document'],
            'opening' => $zero, 'closing' => $total,
            'ageing_0_30' => $total, 'ageing_31_60' => $zero, 'ageing_61_90' => $zero, 'ageing_90_plus' => $zero,
        ]];
    }

    public function live(): bool
    {
        return app(ModuleRegistry::class)->isActive(LoyaltyServiceProvider::MODULE);
    }

    public function load(string $recordId): ?DocumentData
    {
        // RBAC-08: nothing is printed from Loyalty's records while the module is off.
        if (! $this->live()) {
            return null;
        }

        $statement = Statement::query()->find($recordId);

        if ($statement === null) {
            return null;
        }

        $company = Company::query()->findOrFail($statement->company_id);
        $account = Account::query()->findOrFail($statement->account_id);
        $party = Party::query()->find($statement->party_id);
        $view = Statements::view($account, $company, $statement->period_from->format('Y-m-d'), $statement->period_to->format('Y-m-d'));
        $currency = $statement->currency;
        $money = fn (string $minor) => ['minor' => $minor, 'currency' => $currency];
        $zone = $company->timezone ?: 'UTC';
        $ageing = (array) ($statement->ageing['buckets'] ?? []);

        $data = [
            'currencies' => [$currency => app(CurrencyDecimals::class)->for($currency)],
            'company' => [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => is_string($company->address) ? $company->address : null, 'phone' => null, 'email' => null,
                'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => ['name' => null, 'code' => null, 'address' => null],
            'location' => ['name' => null, 'code' => null],
            'document' => [
                'number' => $statement->number,
                'date' => $statement->created_at->setTimezone($zone)->format('Y-m-d'),
                'currency' => $currency,
                'period' => $statement->period_from->format('Y-m-d').' – '.$statement->period_to->format('Y-m-d'),
                'opening' => $money((string) $statement->opening_minor),
                'closing' => $money((string) $statement->closing_minor),
                'ageing_0_30' => $money((string) ($ageing['0_30'] ?? '0')),
                'ageing_31_60' => $money((string) ($ageing['31_60'] ?? '0')),
                'ageing_61_90' => $money((string) ($ageing['61_90'] ?? '0')),
                'ageing_90_plus' => $money((string) ($ageing['90_plus'] ?? '0')),
            ],
            'customer' => $party === null ? null : [
                'name' => $party->name, 'tax_id' => $party->tax_id, 'phone' => $party->phoneNumbers()[0] ?? null,
                'email' => null, 'address' => null, 'tags' => array_values((array) $party->tags),
                'custom' => DataSources::customValues('party', $party->custom),
            ],
            'lines' => array_map(fn (array $line) => [
                'date' => $line['entry']->occurred_at->setTimezone($zone)->format('Y-m-d'),
                'description' => self::describe($line['entry']->kind, $line['entry']->reference, $line['entry']->note),
                'debit' => $line['debit'] === null ? null : $money($line['debit']),
                'credit' => $line['credit'] === null ? null : $money($line['credit']),
                'balance' => $money($line['balance']),
            ], $view['lines']),
            'totals' => [
                'subtotal' => $money((string) $statement->closing_minor),
                'discount' => $money('0'),
                'tax' => $money('0'),
                'total' => $money((string) $statement->closing_minor),
                'dual' => null,
                'tax_lines' => [],
            ],
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];

        return new DocumentData(self::TYPE, $statement->id, $data, $company->id, null, $company->country);
    }

    private static function describe(string $kind, ?string $reference, ?string $note): string
    {
        return trim(__('loyalty.credit.kinds.'.$kind).($reference ? ' '.$reference : '').($note ? ' · '.$note : ''));
    }
}
