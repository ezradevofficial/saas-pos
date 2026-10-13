<?php

namespace App\Core\Onboarding\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's onboarding (ONB-01..ONB-08): one row per tenant, created at
 * sign-up, under row-level security (TEN-01). Every change is audited as
 * `core.onboarding.update` (AUD-01).
 *
 * @property string $stage
 * @property ?string $business_type
 * @property ?array $selection
 * @property ?array $applied
 * @property array $steps
 * @property ?array $sample
 * @property ?array $assisted
 */
class OnboardingState extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const STAGE_BUSINESS = 'business';

    public const STAGE_MODULES = 'modules';

    public const STAGE_SETUP = 'setup';

    public const STAGE_DONE = 'done';

    public const STAGES = [self::STAGE_BUSINESS, self::STAGE_MODULES, self::STAGE_SETUP, self::STAGE_DONE];

    protected string $auditResource = 'onboarding';

    protected $fillable = ['stage', 'business_type', 'selection', 'applied', 'steps', 'trial_started_at', 'trial_ends_at', 'sample', 'checklist_dismissed_at', 'assisted', 'completed_at'];

    protected $attributes = ['stage' => self::STAGE_BUSINESS, 'steps' => '{}'];

    protected function casts(): array
    {
        return [
            'selection' => 'array',
            'applied' => 'array',
            'steps' => 'array',
            'sample' => 'array',
            'assisted' => 'array',
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'checklist_dismissed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** The current tenant's record, created on first use (tenants that signed up before ONB). */
    public static function current(): self
    {
        return static::query()->first() ?? tap(new static, fn (self $state) => $state->save());
    }

    /** The current tenant's record, locked for the rest of the transaction. */
    public static function locked(): self
    {
        static::current();

        return static::query()->lockForUpdate()->firstOrFail();
    }
}
