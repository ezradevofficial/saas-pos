<?php

namespace App\Core\Tenancy\Models;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The customer group at the top of the hierarchy (TEN-02). The table is under
 * RLS keyed on `id`, so a tenant is only visible inside its own context.
 */
class Tenant extends Model
{
    use HasUuids;

    /** Settings keys and their defaults (password_min_length >= 8, session_timeout_minutes 15..480). */
    public const DEFAULT_SETTINGS = [
        'password_min_length' => 8,
        'session_timeout_minutes' => 60,
    ];

    protected $fillable = ['name', 'status', 'default_locale', 'settings'];

    protected $attributes = [
        'status' => 'active',
        'default_locale' => 'en',
        'settings' => '{}',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_sandbox' => 'boolean'];
    }

    /**
     * Create a tenant inside its own (pre-generated) context, as RLS requires.
     * `is_sandbox` (API-05) is set only here, never filled later.
     */
    public static function provision(array $attributes): self
    {
        $id = $attributes['id'] ?? (string) Str::uuid7();
        $sandbox = (bool) ($attributes['is_sandbox'] ?? false);

        return app(TenantContext::class)->run(
            $id,
            fn () => tap(static::make(Arr::except($attributes, ['is_sandbox']))->forceFill(['id' => $id, 'is_sandbox' => $sandbox]))->save(),
        );
    }

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
