<?php

namespace Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PAY-13: one movement of a loan's ledger (append-only, signed:
 * disbursement +, repayment and settlement −, reversal + or −).
 *
 * @property string $loan_id
 * @property string $kind
 * @property int $amount_minor
 * @property ?string $run_id
 * @property CarbonImmutable $entry_date
 */
class LoanEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const DISBURSEMENT = 'disbursement';

    public const REPAYMENT = 'repayment';

    public const SETTLEMENT = 'settlement';

    public const REVERSAL = 'reversal';

    public const UPDATED_AT = null;

    protected $table = 'payroll_loan_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'immutable_date', 'amount_minor' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
