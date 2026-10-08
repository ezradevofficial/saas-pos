<?php

namespace App\Core\MasterData\PaymentMethods;

use App\Core\Audit\Audited;
use App\Core\Audit\Auditor;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's payment method (MD-04): cash in one currency, a mobile money
 * wallet or card linked to a provider, credit, voucher, points or bank
 * transfer, in the till's order (`position`). `settings` holds the
 * provider's plain values; `secrets` its credentials, encrypted at rest,
 * hidden from serialisation and from the audit log: a change to them is
 * audited as `core.payment_method.secrets_change` naming only the keys
 * (AUD-01). Inactive until configured; archived, never deleted (TEN-06).
 */
class PaymentMethod extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const TYPES = ['cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer'];

    /** Any of these at, above or beneath the company lets a user read its payment methods. */
    public const PERMISSIONS = ['core.payment_method.view', 'core.payment_method.create', 'core.payment_method.edit', 'core.payment_method.archive', 'core.payment_method.configure'];

    /** Changes provider settings, secrets and the provider, and switches on mobile money or card methods. */
    public const CONFIGURE = 'core.payment_method.configure';

    /** Types that are linked to a provider (and need one). */
    public const PROVIDER_TYPES = ['mobile_money', 'card'];

    protected $fillable = ['company_id', 'type', 'name', 'currency', 'provider', 'settings', 'secrets', 'active', 'position'];

    protected $hidden = ['secrets'];

    protected array $auditHidden = ['secrets'];

    protected $attributes = [
        'settings' => '{}',
        'active' => false,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'secrets' => 'encrypted:array',
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $marker = function (self $method): void {
            if (! $method->wasChanged('secrets') && ! ($method->wasRecentlyCreated && $method->secrets)) {
                return;
            }

            $before = $method->wasRecentlyCreated ? [] : (array) ($method->getOriginal('secrets') ?? []);
            $after = (array) ($method->secrets ?? []);
            $keys = array_keys($before + $after);
            $changed = array_values(array_filter($keys, fn (string $key) => ($before[$key] ?? null) !== ($after[$key] ?? null)));
            sort($changed);

            if ($changed !== []) {
                app(Auditor::class)->record('core.payment_method.secrets_change', $method, null, ['secrets_changed' => $changed]);
            }
        };

        static::created($marker);
        static::updated($marker);
    }

    public function needsProvider(): bool
    {
        return in_array($this->type, self::PROVIDER_TYPES, true);
    }

    /** True for each secret key that holds a value; never the values. @return array<string, true> */
    public function secretsSet(): array
    {
        return array_map(fn () => true, array_filter((array) ($this->secrets ?? []), fn ($value) => filled($value)));
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
