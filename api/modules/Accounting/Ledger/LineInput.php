<?php

namespace Modules\Accounting\Ledger;

use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use InvalidArgumentException;

/**
 * ACC-03: one journal line as asked for: an account, a side and an amount
 * in its transaction currency. The rate is the source's (CUR-04) or, when
 * null, the core rate on the journal date. A fixed base amount (reversals,
 * revaluation) is stored as given, never recomputed.
 */
final class LineInput
{
    public const DEBIT = 'debit';

    public const CREDIT = 'credit';

    /** @param array{party_id?: ?string, branch_id?: ?string, cost_centre_id?: ?string, department_id?: ?string, project_id?: ?string} $dimensions */
    public function __construct(
        public readonly string $accountId,
        public readonly string $side,
        public readonly Money $amount,
        public readonly ?FxSnapshot $fx = null,
        public readonly ?Money $base = null,
        public readonly ?string $description = null,
        public readonly array $dimensions = [],
    ) {
        if (! in_array($side, [self::DEBIT, self::CREDIT], true)) {
            throw new InvalidArgumentException("Unknown side [{$side}].");
        }

        if ($amount->isNegative() || ($base !== null && $base->isNegative())) {
            throw new InvalidArgumentException('Line amounts are never negative; use the other side.');
        }
    }

    /** A signed amount on its natural side: negative amounts go to the other side. */
    public static function signed(string $accountId, string $side, Money $amount, ?FxSnapshot $fx = null, ?string $description = null, array $dimensions = []): self
    {
        if ($amount->isNegative()) {
            return new self($accountId, $side === self::DEBIT ? self::CREDIT : self::DEBIT, Money::ofMinor(ltrim($amount->minor(), '-'), $amount->currency()), $fx, null, $description, $dimensions);
        }

        return new self($accountId, $side, $amount, $fx, null, $description, $dimensions);
    }

    public function dimension(string $key): ?string
    {
        $value = $this->dimensions[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
