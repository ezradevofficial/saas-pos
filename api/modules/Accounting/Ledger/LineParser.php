<?php

namespace Modules\Accounting\Ledger;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Modules\Accounting\AccountingServiceProvider;

/**
 * ACC-04, ACC-06, ACC-15: journal lines as people type them (validated by
 * the Form Request) into LineInputs: a debit or a credit as a decimal in
 * the line's currency, an optional rate (1 unit of the line's currency =
 * rate units of base) and dimensions. A line in a currency other than the
 * company's base needs Accounting Pro (decision 1).
 */
class LineParser
{
    public function __construct(
        private readonly CurrencyDecimals $decimals,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<LineInput>
     */
    public function parse(Company $company, array $lines): array
    {
        $base = (string) $company->base_currency;
        $inputs = [];

        foreach (array_values($lines) as $index => $line) {
            $currency = (string) ($line['currency'] ?? $base);
            $debit = (string) ($line['debit'] ?? '');
            $credit = (string) ($line['credit'] ?? '');

            if (($debit === '' || BigDecimal::of($debit)->isZero()) === ($credit === '' || BigDecimal::of($credit)->isZero())) {
                throw LedgerError::make('line_one_side', field: "lines.{$index}.debit");
            }

            if ($currency !== $base) {
                $this->assertPro("lines.{$index}.currency");
            }

            $side = $debit !== '' && ! BigDecimal::of($debit)->isZero() ? LineInput::DEBIT : LineInput::CREDIT;
            $amount = Money::parse($side === LineInput::DEBIT ? $debit : $credit, $currency, $this->decimals);
            $rate = $line['rate'] ?? null;
            $fx = $currency === $base || $rate === null || $rate === '' ? null : new FxSnapshot(Rate::normalise((string) $rate), $currency, $base, 'manual', null);

            $inputs[] = new LineInput($line['account_id'], $side, $amount, $fx, null, $line['description'] ?? null, [
                'party_id' => $line['party_id'] ?? null,
                'branch_id' => $line['branch_id'] ?? null,
                'cost_centre_id' => $line['cost_centre_id'] ?? null,
                'department_id' => $line['department_id'] ?? null,
                'project_id' => $line['project_id'] ?? null,
            ]);
        }

        return $inputs;
    }

    /** Decision 1: foreign-currency documents and lines need Accounting Pro. */
    public function assertPro(?string $field = null): void
    {
        if (! $this->modules->isActive(AccountingServiceProvider::PRO)) {
            throw LedgerError::make('accounting_pro_required', field: $field);
        }
    }
}
