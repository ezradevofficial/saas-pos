<?php

namespace Modules\Loyalty\Credit;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\Statement;

/**
 * LOY-17: a credit account's statement for a period (calendar days in the
 * company's time zone): the opening balance, each entry with its running
 * balance, the closing balance and the ageing at the period's end. Stored
 * with its number (`loyalty.statement`, ST-{YYYY}-{00001}) and printed with
 * the `party.statement` template (StatementSource).
 */
class Statements
{
    public const NUMBER_TYPE = 'loyalty.statement';

    public function __construct(
        private readonly Numbering $numbering,
        private readonly Auditor $auditor,
    ) {}

    public function create(Account $account, string $from, string $to, User $by): Statement
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($account, $from, $to, $by) {
            $company = Company::query()->findOrFail($account->company_id);
            $view = self::view($account, $company, $from, $to);
            $number = $this->numbering->next(self::NUMBER_TYPE, new NumberContext($company, at: CarbonImmutable::now()));

            $statement = Statement::create([
                'account_id' => $account->id,
                'company_id' => $account->company_id,
                'party_id' => $account->party_id,
                'number' => $number->number,
                'period_from' => $from,
                'period_to' => $to,
                'currency' => $account->currency,
                'opening_minor' => $view['opening_minor'],
                'closing_minor' => $view['closing_minor'],
                'ageing' => $view['ageing'],
                'created_by' => $by->id,
            ]);
            $this->auditor->record('loyalty.statement.create', $statement, null, [
                'number' => $statement->number, 'account_id' => $account->id, 'period_from' => $from, 'period_to' => $to,
                'closing' => ['amount_minor' => $view['closing_minor'], 'currency' => $account->currency],
            ], ['user_id' => $by->id]);

            return $statement;
        });
    }

    /**
     * The statement's figures, computed from the ledger.
     *
     * @return array{opening_minor: string, closing_minor: string, lines: list<array{entry: CreditEntry, debit: ?string, credit: ?string, balance: string}>, ageing: array}
     */
    public static function view(Account $account, Company $company, string $from, string $to): array
    {
        $zone = $company->timezone ?: 'UTC';
        $start = CarbonImmutable::parse($from, $zone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $zone)->addDay()->startOfDay()->utc();
        $entries = CreditEntry::query()->where('account_id', $account->id)->where('occurred_at', '<', $end)->orderBy('occurred_at')->orderBy('id')->get();

        $balance = BigInteger::zero();
        $opening = null;
        $lines = [];

        foreach ($entries as $entry) {
            if ($entry->occurred_at->lessThan($start)) {
                $balance = $balance->plus((string) $entry->amount_minor);

                continue;
            }

            $opening ??= $balance;
            $amount = BigInteger::of((string) $entry->amount_minor);
            $balance = $balance->plus($amount);
            $lines[] = [
                'entry' => $entry,
                'debit' => $amount->isPositive() ? (string) $amount : null,
                'credit' => $amount->isNegative() ? (string) $amount->negated() : null,
                'balance' => (string) $balance,
            ];
        }

        return [
            'opening_minor' => (string) ($opening ?? $balance),
            'closing_minor' => (string) $balance,
            'lines' => $lines,
            'ageing' => Ageing::of($entries, $end->subMicrosecond(), $zone),
        ];
    }
}
